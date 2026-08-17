<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A durable, resumable event log for one assistant turn — generalized from
 * the catodemy / s-grade twins. Generation runs in a background job,
 * decoupled from any HTTP connection: the job appends streamed events here
 * as it produces them, and the SSE endpoint tails them by sequence number,
 * so a dropped connection (or a user who leaves and comes back) resumes
 * exactly where it left off via `?cursor=<last seq>` / `Last-Event-ID`.
 *
 * Events should be coalesced by the producer so the log stays small (tens
 * of entries per turn), keeping the read-modify-write cheap on any cache
 * driver.
 *
 * Cancellation lives under its OWN cache key — never a read-modify-write on
 * the turn record — so a user's "stop" can never race the generating job's
 * appends. The job polls {@see isCancelled} between events and finishes
 * early with whatever it has. {@see claimPersist} is the atomic one-time
 * right to persist a finished turn, so concurrent tailers/tabs append the
 * final message exactly once.
 *
 * Record shape: {status: running|done|failed, cursor: int,
 * events: list<{seq, event, data}>, meta: array}. `meta` is the app's bag
 * (owner id for cancel authorization, conversation id, final message, ...).
 */
class TurnBuffer
{
    public function __construct(
        protected Cache $cache,
        protected int $ttlSeconds = 7200,
        protected int $maxStreamSeconds = 180,
        protected int $keepaliveSeconds = 15,
        protected int $pollIntervalMs = 150,
    ) {}

    /**
     * Begin a turn: an empty, running log the SSE endpoint can immediately
     * tail even before the job has produced anything. `$meta` records app
     * facts about the turn (e.g. its owner, so a cancel endpoint can refuse
     * foreign turns).
     *
     * @param  array<string, mixed>  $meta
     */
    public function start(string $turnId, array $meta = []): void
    {
        $this->cache->put($this->key($turnId), [
            'status' => 'running',
            'cursor' => 0,
            'events' => [],
            'meta' => $meta,
        ], $this->ttlSeconds);
    }

    /**
     * Append one event, assigning it the next sequence number. A no-op on
     * an unknown or expired turn.
     *
     * @param  array<string, mixed>  $data
     */
    public function append(string $turnId, string $event, array $data): void
    {
        $turn = $this->get($turnId);

        if ($turn === null) {
            return;
        }

        $turn['cursor']++;
        $turn['events'][] = ['seq' => $turn['cursor'], 'event' => $event, 'data' => $data];

        $this->cache->put($this->key($turnId), $turn, $this->ttlSeconds);
    }

    /**
     * Mark the turn complete: append the terminal `done` event carrying
     * `$done` and fold `$meta` into the record for tailers to decorate
     * with (conversation id, final message, credit outcome, ...).
     *
     * @param  array<string, mixed>  $done
     * @param  array<string, mixed>  $meta
     */
    public function finish(string $turnId, array $done = [], array $meta = []): void
    {
        $this->append($turnId, 'done', $done);
        $this->complete($turnId, 'done', $meta);
    }

    /**
     * Mark the turn failed: append `error {message}` then a terminal `done`
     * (carrying `$done`), mirroring the inline streaming contract so the
     * client's handling is identical on both paths.
     *
     * @param  array<string, mixed>  $done
     * @param  array<string, mixed>  $meta
     */
    public function fail(string $turnId, string $message, array $done = [], array $meta = []): void
    {
        $this->append($turnId, 'error', ['message' => $message]);
        $this->append($turnId, 'done', $done);
        $this->complete($turnId, 'failed', $meta + ['error' => $message]);
    }

    /**
     * Request cancellation of a running turn (the user pressed "stop").
     * Stored under its own key so it cannot race the producer's appends.
     */
    public function cancel(string $turnId): void
    {
        $this->cache->put($this->cancelKey($turnId), true, $this->ttlSeconds);
    }

    public function isCancelled(string $turnId): bool
    {
        return (bool) $this->cache->get($this->cancelKey($turnId), false);
    }

    /**
     * The current turn record, or null if it is unknown or has expired.
     *
     * @return array{status: string, cursor: int, events: list<array{seq: int, event: string, data: array<string, mixed>}>, meta: array<string, mixed>}|null
     */
    public function get(string $turnId): ?array
    {
        return $this->cache->get($this->key($turnId));
    }

    /**
     * The events after the given cursor, for a client resuming at
     * `?cursor=<last seq>`.
     *
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    public function eventsAfter(string $turnId, int $cursor): array
    {
        $turn = $this->get($turnId);

        if ($turn === null) {
            return [];
        }

        return array_values(array_filter(
            $turn['events'],
            fn (array $event): bool => $event['seq'] > $cursor,
        ));
    }

    /**
     * Claim the one-time right to persist a finished turn. Atomic across
     * concurrent tailers/tabs so the final message is appended exactly once.
     */
    public function claimPersist(string $turnId): bool
    {
        return $this->cache->add($this->persistKey($turnId), true, $this->ttlSeconds);
    }

    public function forget(string $turnId): void
    {
        $this->cache->forget($this->key($turnId));
        $this->cache->forget($this->persistKey($turnId));
        $this->cache->forget($this->cancelKey($turnId));
    }

    /**
     * Tail the turn as SSE frames on the given stream, resuming after the
     * given cursor. Each frame carries an `id:` line (its sequence number).
     * The loop polls the record, emits new events, keeps the connection
     * alive with periodic comments, and ends when the turn drains after
     * finishing, the client goes away, the record expires, or the deadline
     * passes (the client's EventSource reconnects with its last id).
     *
     * `$decorate` — `fn (string $event, array $data, array $record): array`
     * — rewrites an event's payload at emit time; apps fold record meta
     * into the terminal `done` there (conversation id, credit outcome).
     *
     * @return int the last sequence number emitted
     */
    public function tail(
        string $turnId,
        int $after = 0,
        ?SseStream $stream = null,
        ?callable $decorate = null,
        ?int $maxSeconds = null,
        ?int $keepaliveSeconds = null,
    ): int {
        $stream ??= app(SseStream::class);
        $keepalive = $keepaliveSeconds ?? $this->keepaliveSeconds;

        $lastSeq = max(0, $after);
        $deadline = microtime(true) + ($maxSeconds ?? $this->maxStreamSeconds);
        $idleSince = microtime(true);
        $record = $this->get($turnId);

        while ($record !== null) {
            foreach ($record['events'] as $event) {
                if ($event['seq'] <= $lastSeq) {
                    continue;
                }

                $data = $decorate !== null
                    ? $decorate($event['event'], $event['data'], $record)
                    : $event['data'];

                $stream->emit($event['event'], $data, $event['seq']);
                $lastSeq = $event['seq'];
                $idleSince = microtime(true);
            }

            // Fully drained a finished turn — close cleanly.
            if ($record['status'] !== 'running' && $lastSeq >= $record['cursor']) {
                break;
            }

            if (microtime(true) - $idleSince >= $keepalive) {
                $stream->comment('keepalive');
                $idleSince = microtime(true);
            }

            if ($stream->aborted() || microtime(true) >= $deadline) {
                break;
            }

            usleep($this->pollIntervalMs * 1000);

            // Re-read for the next pass; a null means the turn expired
            // mid-stream and the loop guard closes the connection.
            $record = $this->get($turnId);
        }

        return $lastSeq;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function complete(string $turnId, string $status, array $meta): void
    {
        $turn = $this->get($turnId);

        if ($turn === null) {
            return;
        }

        $turn['status'] = $status;
        $turn['meta'] = array_replace($turn['meta'] ?? [], $meta);

        $this->cache->put($this->key($turnId), $turn, $this->ttlSeconds);
    }

    protected function key(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}";
    }

    protected function persistKey(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}:persisted";
    }

    protected function cancelKey(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}:cancelled";
    }
}

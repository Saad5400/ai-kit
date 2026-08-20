<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * A durable, resumable event log for one assistant turn — generalized from
 * the catodemy / s-grade twins. Generation runs in a background job,
 * decoupled from any HTTP connection: the job appends streamed events here
 * as it produces them, and the SSE endpoint tails them by sequence number,
 * so a dropped connection (or a user who leaves and comes back) resumes
 * exactly where it left off via `?cursor=<last seq>` / `Last-Event-ID`.
 *
 * STORAGE. A turn is a small HEADER plus PAGES of events, so an append
 * costs O(page_size) regardless of how long the turn has run:
 *
 *   ai-kit:turn:{id}      {status, cursor, meta, heartbeat_at, started_at, page_size}
 *   ai-kit:turn:{id}:p{n} list<{seq, event, data}>  for seqs (n·page_size, (n+1)·page_size]
 *
 * A ten-minute turn used to re-serialize its whole record on every delta
 * (O(n²) cache I/O); now `append()` touches the header and the current page
 * only. Every write re-puts with the TTL, so the TTL slides for the life of
 * the turn, and finishing re-puts every page so the complete log outlives
 * the turn by one full TTL. Producers should still coalesce deltas and use
 * {@see upsert()} for progress-style events so the log stays small.
 *
 * LIVENESS. The header carries a `heartbeat_at` the producer refreshes with
 * every append and, between appends, with {@see touch()}. A tail that finds
 * a running turn whose heartbeat is older than `staleAfterSeconds` writes
 * the terminal `error` itself (meta `stale: true`) — a worker killed
 * mid-turn (OOM, deploy, timeout) no longer leaves the client spinning until
 * the TTL. Because {@see append()} refuses writes once the turn is no longer
 * running, a producer that wakes up late cannot write past that terminal.
 *
 * TERMINAL-EVENT CONTRACT (identical here and on the inline path through
 * {@see StreamEventMapper}): a turn ends with EXACTLY ONE terminal event —
 * `done {...}` when it completed, or `error {message}` when it did not.
 * `error` is terminal; no `done` ever follows it. A client that tears its
 * stream down on either event therefore behaves the same whether the turn
 * was streamed inline or replayed out of this buffer. ({@see fail()} has an
 * opt-in trailing `done` for clients that only tear down on that event —
 * off by default, so the contract holds unless an app breaks it knowingly.)
 *
 * Cancellation lives under its OWN cache key — never a read-modify-write on
 * the turn record — so a user's "stop" can never race the generating job's
 * appends. The job polls {@see isCancelled} between events and finishes
 * early with whatever it has. {@see claimPersist} is the atomic one-time
 * right to persist a finished turn, so concurrent tailers/tabs append the
 * final message exactly once.
 *
 * `meta` is the app's bag (owner id for cancel authorization, conversation
 * id, final message, ...). Records written before the header/pages split
 * (an inline `events` list) still read back, so a deploy never strands the
 * turns in flight.
 */
class TurnBuffer
{
    public function __construct(
        protected Cache $cache,
        protected int $ttlSeconds = 7200,
        protected int $maxStreamSeconds = 180,
        protected int $keepaliveSeconds = 15,
        protected int $pollIntervalMs = 150,
        protected int $pageSize = 64,
        protected int $staleAfterSeconds = 300,
        protected bool $staleTrailingDone = false,
    ) {}

    /**
     * Begin a turn: an empty, running log the SSE endpoint can immediately
     * tail even before the job has produced anything. `$meta` records app
     * facts about the turn (e.g. its owner, so a cancel endpoint can refuse
     * foreign turns). Pages left by an earlier run of the same id (a retried
     * job) are dropped first, so "start" always means an empty log.
     *
     * @param  array<string, mixed>  $meta
     */
    public function start(string $turnId, array $meta = []): void
    {
        $this->forgetPages($turnId);

        $now = $this->now();

        $this->cache->put($this->key($turnId), [
            'status' => 'running',
            'cursor' => 0,
            'meta' => $meta,
            'heartbeat_at' => $now,
            'started_at' => $now,
            'page_size' => max(1, $this->pageSize),
        ], $this->ttlSeconds);
    }

    /**
     * Append one event, assigning it the next sequence number and stamping
     * the heartbeat. A no-op on an unknown or expired turn AND on a turn
     * that is no longer running: once a terminal event is in the log,
     * nothing — not even a producer that wakes up after the tail declared
     * it stale — may write past it.
     *
     * SINGLE WRITER: exactly one producer — the turn's generating job —
     * appends to a turn. The read-modify-write here is deliberately
     * lock-free on that assumption; two workers appending to the same turn
     * would silently lose events, so never fan one turn's generation out
     * across jobs. (Tailers only read, and cancellation has its own key.
     * The one write a tailer ever makes is the stale terminal, and it is
     * gated by an atomic claim.)
     *
     * The page is written BEFORE the header, so a tailer that reads a
     * cursor of N always finds at least N events in the pages.
     *
     * @param  array<string, mixed>  $data
     */
    public function append(string $turnId, string $event, array $data): void
    {
        $header = $this->header($turnId);

        if ($header === null || $header['status'] !== 'running') {
            return;
        }

        $this->write($turnId, $header, $event, $data);
    }

    /**
     * Append — or, when the LAST entry in the log is the same event about
     * the same thing (`data[$key]` equal), replace that entry in place with
     * a fresh sequence number. A six-minute tool reporting progress three
     * hundred times stays ONE log entry, yet every client — live or resuming
     * — still receives the latest state because the seq advances. Seqs stay
     * ascending in page order since only the tail entry is ever rewritten.
     *
     * Entries without `$key` in their data never match; they append. Same
     * single-writer rule as {@see append()}.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(string $turnId, string $event, array $data, string $key = 'id'): void
    {
        $header = $this->header($turnId);

        if ($header === null || $header['status'] !== 'running') {
            return;
        }

        if (array_key_exists('events', $header)) {
            // Legacy inline record — no paging to keep consistent.
            $last = $header['events'][array_key_last($header['events'])] ?? null;

            if ($last !== null && $this->sameSubject($last, $event, $data, $key)) {
                array_pop($header['events']);
            }

            $this->write($turnId, $header, $event, $data);

            return;
        }

        $cursor = (int) $header['cursor'];

        if ($cursor === 0) {
            $this->write($turnId, $header, $event, $data);

            return;
        }

        $pageNo = $this->pageNumber($cursor, $header['page_size']);
        $page = $this->page($turnId, $pageNo);
        $last = $page[array_key_last($page)] ?? null;

        if ($last === null || ! $this->sameSubject($last, $event, $data, $key)) {
            $this->write($turnId, $header, $event, $data);

            return;
        }

        array_pop($page);

        $seq = $cursor + 1;
        $targetPageNo = $this->pageNumber($seq, $header['page_size']);

        if ($targetPageNo === $pageNo) {
            $page[] = ['seq' => $seq, 'event' => $event, 'data' => $data];
            $this->cache->put($this->pageKey($turnId, $pageNo), $page, $this->ttlSeconds);
        } else {
            // The replacement rolls over onto a new page: the old page loses
            // its tail and the new page starts with the replacement.
            $this->cache->put($this->pageKey($turnId, $pageNo), $page, $this->ttlSeconds);
            $this->cache->put($this->pageKey($turnId, $targetPageNo), [
                ['seq' => $seq, 'event' => $event, 'data' => $data],
            ], $this->ttlSeconds);
        }

        $header['cursor'] = $seq;
        $header['heartbeat_at'] = $this->now();

        $this->cache->put($this->key($turnId), $header, $this->ttlSeconds);
    }

    /**
     * Refresh the heartbeat (and slide the TTL) without appending. The
     * producer calls this while it is busy but silent — inside a long tool
     * call, between batches — at an interval comfortably shorter than
     * `staleAfterSeconds`, or the tail will declare the turn dead. A
     * producer-side call under the same single-writer rule; a no-op once the
     * turn is no longer running.
     */
    public function touch(string $turnId): void
    {
        $header = $this->header($turnId);

        if ($header === null || $header['status'] !== 'running') {
            return;
        }

        $header['heartbeat_at'] = $this->now();

        $this->cache->put($this->key($turnId), $header, $this->ttlSeconds);
    }

    /**
     * Mark the turn complete: append the ONE terminal `done` event carrying
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
     * Mark the turn failed: append the terminal `error {message}` and stop.
     * No `done` follows — a failed turn has no completion payload to carry,
     * and emitting both would leave clients guessing which one ended the
     * turn. The failure is also recorded in the record's meta.
     *
     * `$trailingDone` opts one app out of that, appending an empty `done`
     * after the `error`. Some clients hang their whole teardown off `done`
     * — closing the reader, clearing the "streaming" flag, re-enabling the
     * composer — and treat `error` as nothing but a message to display; an
     * `error`-only turn leaves such a client spinning until its own timeout.
     * Fixing the client is the better fix; this is here for the ones that
     * ship elsewhere. Default OFF: the contract above is what the kit
     * promises unless an app asks for otherwise.
     *
     * @param  array<string, mixed>  $meta
     */
    public function fail(string $turnId, string $message, array $meta = [], bool $trailingDone = false): void
    {
        $this->append($turnId, 'error', ['message' => $message]);

        if ($trailingDone) {
            $this->append($turnId, 'done', []);
        }

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
     * The FULL turn record — header plus every event assembled from the
     * pages — or null if it is unknown or has expired. This is the
     * EXPENSIVE read (it loads every page); controllers that only need to
     * know whether a turn exists or how it ended should use {@see exists()}
     * / {@see status()}, and tailers read only the pages past their cursor.
     *
     * @return array{status: string, cursor: int, events: list<array{seq: int, event: string, data: array<string, mixed>}>, meta: array<string, mixed>, heartbeat_at?: int, started_at?: int, page_size?: int}|null
     */
    public function get(string $turnId): ?array
    {
        $header = $this->header($turnId);

        if ($header === null) {
            return null;
        }

        if (array_key_exists('events', $header)) {
            return $header;
        }

        return [
            'status' => $header['status'],
            'cursor' => $header['cursor'],
            'events' => $this->eventsFrom($turnId, $header, 0),
            'meta' => $header['meta'],
        ] + $header;
    }

    /**
     * The turn's status (`running` | `done` | `failed`), or null if it is
     * unknown or has expired. Header-only: cheap on any driver.
     */
    public function status(string $turnId): ?string
    {
        return $this->header($turnId)['status'] ?? null;
    }

    /**
     * Whether the turn is known and unexpired. Header-only.
     */
    public function exists(string $turnId): bool
    {
        return $this->header($turnId) !== null;
    }

    /**
     * The events after the given cursor, for a client resuming at
     * `?cursor=<last seq>`. Reads only the pages from the cursor's page on.
     *
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    public function eventsAfter(string $turnId, int $cursor): array
    {
        $header = $this->header($turnId);

        if ($header === null) {
            return [];
        }

        return $this->eventsFrom($turnId, $header, $cursor);
    }

    /**
     * Claim the one-time right to persist a finished turn. Atomic across
     * concurrent tailers/tabs so the final message is appended exactly once.
     */
    public function claimPersist(string $turnId): bool
    {
        return $this->cache->add($this->persistKey($turnId), true, $this->ttlSeconds);
    }

    /**
     * Remove the turn entirely: header, every page, and the side keys
     * (persist claim, cancel flag, stale claim).
     */
    public function forget(string $turnId): void
    {
        $this->forgetPages($turnId);

        $this->cache->forget($this->key($turnId));
        $this->cache->forget($this->persistKey($turnId));
        $this->cache->forget($this->cancelKey($turnId));
        $this->cache->forget($this->staleKey($turnId));
    }

    /**
     * Tail the turn as SSE frames on the given stream, resuming after the
     * given cursor. Each frame carries an `id:` line (its sequence number).
     * Each pass reads the header and only the pages past the last emitted
     * seq, emits what is new, keeps the connection alive with periodic
     * comments, and ends when the turn is terminal and drained, the client
     * goes away, the record expires, or the deadline passes (the client's
     * EventSource reconnects with its last id).
     *
     * STALE TURNS. A running turn whose heartbeat is older than
     * `staleAfterSeconds` is failed right here — terminal `error` with
     * `$staleMessage` (default `ai-kit::streaming.stale`), meta
     * `stale: true`, and a trailing `done` when the buffer was built with
     * `staleTrailingDone` — then drained like any failed turn. An atomic
     * claim keeps concurrent tailers from writing two terminals. Records
     * from before the heartbeat existed fall back to `started_at`, and
     * failing that to the moment this tail first saw them, so an old
     * in-flight turn is never declared dead on its first poll. A
     * `staleAfterSeconds` of 0 or less disables the check.
     *
     * `$decorate` — `fn (string $event, array $data, array $record): array`
     * — rewrites an event's payload at emit time; apps fold record meta
     * into the terminal `done` there (conversation id, credit outcome).
     * The `$record` it receives is the header (no `events`).
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
        ?string $staleMessage = null,
    ): int {
        $stream ??= app(SseStream::class);
        $keepalive = $keepaliveSeconds ?? $this->keepaliveSeconds;

        $lastSeq = max(0, $after);
        $deadline = microtime(true) + ($maxSeconds ?? $this->maxStreamSeconds);
        $idleSince = microtime(true);
        $firstSeenAt = $this->now();

        while (($header = $this->header($turnId)) !== null) {
            foreach ($this->eventsFrom($turnId, $header, $lastSeq) as $event) {
                $data = $decorate !== null
                    ? $decorate($event['event'], $event['data'], $header)
                    : $event['data'];

                $stream->emit($event['event'], $data, $event['seq']);
                $lastSeq = $event['seq'];
                $idleSince = microtime(true);
            }

            // Terminal and drained — nothing can be appended past a
            // terminal, so whatever we just read is all there will be.
            if ($header['status'] !== 'running') {
                break;
            }

            if ($this->isStale($header, $firstSeenAt)) {
                if ($this->cache->add($this->staleKey($turnId), true, $this->ttlSeconds)) {
                    $this->fail(
                        $turnId,
                        $staleMessage ?? __('ai-kit::streaming.stale'),
                        ['stale' => true],
                        $this->staleTrailingDone,
                    );

                    // Drain the terminal we just wrote, deadline or not.
                    continue;
                }

                // A sibling tailer holds the claim: give it a beat to land
                // the terminal, then re-read — but never past our deadline,
                // in case that sibling died before writing it.
                if ($stream->aborted() || microtime(true) >= $deadline) {
                    break;
                }

                usleep($this->pollIntervalMs * 1000);

                continue;
            }

            if (microtime(true) - $idleSince >= $keepalive) {
                $stream->comment('keepalive');
                $idleSince = microtime(true);
            }

            if ($stream->aborted() || microtime(true) >= $deadline) {
                break;
            }

            usleep($this->pollIntervalMs * 1000);
        }

        return $lastSeq;
    }

    /**
     * Flip the header to a terminal status and fold in meta. A no-op once
     * the turn is already terminal, so a producer finishing after the tail
     * declared it stale cannot overwrite the failure. Every page is re-put
     * with the TTL so the whole log — not just the header and the last page
     * — stays readable for one full TTL after the turn ends.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function complete(string $turnId, string $status, array $meta): void
    {
        $header = $this->header($turnId);

        if ($header === null || $header['status'] !== 'running') {
            return;
        }

        if (! array_key_exists('events', $header)) {
            for ($n = 0; $n <= $this->lastPageNumber($header); $n++) {
                $page = $this->cache->get($this->pageKey($turnId, $n));

                if ($page !== null) {
                    $this->cache->put($this->pageKey($turnId, $n), $page, $this->ttlSeconds);
                }
            }
        }

        $header['status'] = $status;
        $header['meta'] = array_replace($header['meta'] ?? [], $meta);
        $header['heartbeat_at'] = $this->now();

        $this->cache->put($this->key($turnId), $header, $this->ttlSeconds);
    }

    /**
     * Append `$event` as the next seq of a running header and write it back.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $data
     */
    protected function write(string $turnId, array $header, string $event, array $data): void
    {
        $seq = ++$header['cursor'];
        $entry = ['seq' => $seq, 'event' => $event, 'data' => $data];

        if (array_key_exists('events', $header)) {
            $header['events'][] = $entry;
        } else {
            $pageNo = $this->pageNumber($seq, $header['page_size']);
            $page = $this->page($turnId, $pageNo);
            $page[] = $entry;

            $this->cache->put($this->pageKey($turnId, $pageNo), $page, $this->ttlSeconds);
        }

        $header['heartbeat_at'] = $this->now();

        $this->cache->put($this->key($turnId), $header, $this->ttlSeconds);
    }

    /**
     * The events with seq > `$after`, reading only the pages that can hold
     * them. A page that has expired ahead of the header simply contributes
     * nothing — the log is best-effort past its TTL, never an exception.
     *
     * @param  array<string, mixed>  $header
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    protected function eventsFrom(string $turnId, array $header, int $after): array
    {
        if (array_key_exists('events', $header)) {
            return array_values(array_filter(
                $header['events'],
                fn (array $event): bool => $event['seq'] > $after,
            ));
        }

        if ((int) $header['cursor'] <= $after) {
            return [];
        }

        $events = [];
        $lastPage = $this->lastPageNumber($header);

        for ($n = intdiv($after, $this->pageSizeOf($header)); $n <= $lastPage; $n++) {
            foreach ($this->page($turnId, $n) as $event) {
                if ($event['seq'] > $after) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function isStale(array $header, int $firstSeenAt): bool
    {
        if ($this->staleAfterSeconds <= 0) {
            return false;
        }

        $beat = (int) ($header['heartbeat_at'] ?? $header['started_at'] ?? $firstSeenAt);

        return $this->now() - $beat > $this->staleAfterSeconds;
    }

    /**
     * @param  array{event: string, data: array<string, mixed>}  $entry
     * @param  array<string, mixed>  $data
     */
    protected function sameSubject(array $entry, string $event, array $data, string $key): bool
    {
        return $entry['event'] === $event
            && array_key_exists($key, $data)
            && array_key_exists($key, $entry['data'])
            && $entry['data'][$key] === $data[$key];
    }

    protected function forgetPages(string $turnId): void
    {
        $header = $this->header($turnId);

        if ($header === null || array_key_exists('events', $header)) {
            return;
        }

        for ($n = 0; $n <= $this->lastPageNumber($header); $n++) {
            $this->cache->forget($this->pageKey($turnId, $n));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function header(string $turnId): ?array
    {
        return $this->cache->get($this->key($turnId));
    }

    /**
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    protected function page(string $turnId, int $n): array
    {
        return $this->cache->get($this->pageKey($turnId, $n), []);
    }

    /**
     * The page holding a given seq: seqs 1..page_size live on page 0.
     */
    protected function pageNumber(int $seq, int $pageSize): int
    {
        return intdiv(max(1, $seq) - 1, max(1, $pageSize));
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function lastPageNumber(array $header): int
    {
        return $this->pageNumber((int) $header['cursor'], $this->pageSizeOf($header));
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function pageSizeOf(array $header): int
    {
        return max(1, (int) ($header['page_size'] ?? $this->pageSize));
    }

    /**
     * Unix seconds, through Carbon so tests can travel.
     */
    protected function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    protected function key(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}";
    }

    protected function pageKey(string $turnId, int $n): string
    {
        return "ai-kit:turn:{$turnId}:p{$n}";
    }

    protected function persistKey(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}:persisted";
    }

    protected function cancelKey(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}:cancelled";
    }

    protected function staleKey(string $turnId): string
    {
        return "ai-kit:turn:{$turnId}:stale";
    }
}

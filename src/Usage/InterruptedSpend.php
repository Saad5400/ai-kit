<?php

namespace Saad\AiKit\Usage;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Saad\AiKit\Gateway\GenerationCostResolver;
use Saad\AiKit\Gateway\GenerationCostUnavailable;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Usage\Events\InterruptedSpendResolved;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Throwable;

/**
 * Prices the PENDING generations a turn left behind — steps a stop or a
 * mid-stream failure cut off after OpenRouter assigned the generation id
 * but before the final chunk carried `usage.cost` — and reports them as
 * {@see InterruptedSpendResolved}.
 *
 * The usage listeners are the single entry point ({@see track()}), right
 * after writing a turn's row: the `stopped`/`failed` row of an interrupted
 * turn, or the completed row of a turn whose failover attempt or sub-agent
 * was cut off on the way. Every priced generation is recorded on the daily
 * budget at once, keyed per generation id; the event fires once, after
 * everything priced — or, when OpenRouter never prices some, with what
 * did — normally from the queued {@see ResolveInterruptedSpend}.
 *
 * Metering never breaks a turn: nothing here throws into the caller. A
 * queue that refuses the job, or a configuration that can never price
 * (see {@see GenerationCostUnavailable}), settles with what was priced and
 * logs the rest.
 */
class InterruptedSpend
{
    /**
     * The status of a delta row: the late-priced spend of a turn, which is
     * not a turn of its own (so turn counts by status stay one per turn).
     */
    public const RESOLVED_STATUS = 'resolved';

    public const COST_SOURCE = 'generation_lookup';

    /** {@see finish()} outcomes. */
    public const FIRED = 'fired';

    public const SKIPPED = 'skipped';

    public const BUSY = 'busy';

    /**
     * @param  array<string, mixed>  $config  the ai-kit.spend config section
     */
    public function __construct(
        protected GenerationCostResolver $resolver,
        protected Container $container,
        protected Repository $cache,
        protected array $config = [],
    ) {}

    /**
     * Take over the pending generations of the turn recorded as `$turn`.
     * Never throws.
     *
     * @param  list<string>  $pendingGenerationIds
     * @param  array<string, mixed>  $meta
     */
    public function track(UsageEvent $turn, array $pendingGenerationIds, ?string $turnId = null, array $meta = []): void
    {
        $pending = array_values(array_unique($pendingGenerationIds));

        if ($pending === [] || ! $this->enabled()) {
            return;
        }

        // A configuration that can never price: say so once, loudly, rather
        // than queue five attempts that each fail the same way.
        if (($reason = $this->resolver->misconfiguration()) !== null) {
            $this->unavailable($turn, $pending, new GenerationCostUnavailable($reason));

            return;
        }

        $resolved = [];

        try {
            // Opt-in, and never for a failed turn: during an OpenRouter
            // outage every failed turn would hold its worker for the window.
            if ($turn->status !== TurnUsageRecorded::FAILED) {
                foreach ($this->resolver->resolveMany($pending) as $id => $cost) {
                    $resolved[(string) $id] = $cost;
                    $this->recordResolved((string) $id, $cost);
                }
            }

            $unresolved = array_values(array_diff($pending, array_keys($resolved)));
            $job = new ResolveInterruptedSpend((int) $turn->getKey(), $pending, $resolved, $turnId, $meta);

            if ($unresolved === []) {
                $this->settle($job, $resolved);

                return;
            }

            if (! $this->queueable()) {
                $this->gaveUp($turn, $unresolved, 0, 'the spend queue connection is `sync`, which cannot delay a retry');
                $this->settle($job, $resolved);

                return;
            }

            $delays = $this->retryDelays();

            $this->queue($job, $delays[0] ?? 0);
        } catch (GenerationCostUnavailable $e) {
            $this->unavailable($turn, $pending, $e);
            $this->settleQuietly($turn, $resolved, $pending, $turnId, $meta);
        } catch (Throwable $e) {
            // The queue refused the job (or anything else went wrong): the
            // turn is not the place for it. What priced is still reported.
            report($e);

            $this->gaveUp($turn, array_values(array_diff($pending, array_keys($resolved))), 0, 'the pricing job could not be queued');
            $this->settleQuietly($turn, $resolved, $pending, $turnId, $meta);
        }
    }

    /**
     * Budget a priced generation, once per generation id — a retried job or a
     * redelivered payload never counts it twice.
     */
    public function recordResolved(string $generationId, float $costUsd): void
    {
        if ($costUsd <= 0 || ! ($this->config['record_budget'] ?? true) || ! $this->container->bound(BudgetGuard::class)) {
            return;
        }

        rescue(fn () => $this->container->make(BudgetGuard::class)->recordOnce('interrupted:generation:'.$generationId, $costUsd));
    }

    /**
     * Write the delta row and fire the event — once per turn row.
     *
     * A short lock covers the call; the "done" marker is set only after the
     * listeners returned, so a worker killed in between (a deploy) leaves
     * the settlement to its redelivery: the row is found and reused, the
     * event fires again, and the app's own debit key dedups it. Returns
     * {@see FIRED}, {@see SKIPPED} (already done, or nothing to report) or
     * {@see BUSY} (another worker holds the lock — retry later).
     *
     * @param  array<string, float>  $resolved
     * @param  list<string>  $pending  the settlement's full generation set
     * @param  array<string, mixed>  $meta
     */
    public function finish(int $turnUsageId, array $resolved, array $pending, ?string $turnId, array $meta): string
    {
        $cost = (float) array_sum($resolved);

        if ($cost <= 0) {
            return self::SKIPPED;
        }

        $key = 'ai-kit:interrupted-spend:'.$turnUsageId.':'.self::fingerprint($pending);

        if ($this->cache->has($key.':done')) {
            return self::SKIPPED;
        }

        if (! $this->cache->add($key.':lock', 1, 120)) {
            return self::BUSY;
        }

        try {
            $turn = UsageEvent::query()->find($turnUsageId);

            if ($turn === null) {
                return self::SKIPPED;
            }

            $generationIds = array_values(array_map('strval', array_keys($resolved)));

            $delta = UsageEvent::query()
                ->where('invocation_id', $turn->invocation_id)
                ->where('status', self::RESOLVED_STATUS)
                ->first()
                ?? UsageEvent::create([
                    'invocation_id' => $turn->invocation_id,
                    'conversation_id' => $turn->conversation_id,
                    'participant_type' => $turn->participant_type,
                    'participant_id' => $turn->participant_id,
                    'agent' => $turn->agent,
                    'feature' => $turn->feature,
                    'provider' => $turn->provider,
                    'model' => $turn->model,
                    'streamed' => $turn->streamed,
                    'cost_usd' => $cost,
                    'cost_source' => self::COST_SOURCE,
                    'generation_ids' => $generationIds,
                    'status' => self::RESOLVED_STATUS,
                    'context' => array_filter([
                        'resolves' => $turn->getKey(),
                        'turn_status' => $turn->status,
                        'turn_id' => $turnId,
                    ], fn ($value) => $value !== null),
                    'created_at' => now(),
                ]);

            $this->container->make('events')->dispatch(
                new InterruptedSpendResolved($delta, $turn, $turnId, $cost, $generationIds, $meta),
            );

            $this->cache->put($key.':done', 1, now()->addDays(2));

            return self::FIRED;
        } finally {
            $this->cache->forget($key.':lock');
        }
    }

    /**
     * Seconds before each queued pricing attempt; its length is the number
     * of attempts.
     *
     * @return list<int>
     */
    public function retryDelays(): array
    {
        return array_values(array_map('intval', $this->config['retry_delays_seconds'] ?? [5, 20, 60, 180, 300]));
    }

    public function queue(ResolveInterruptedSpend $job, int $delaySeconds): void
    {
        $job->onConnection($this->config['connection'] ?? null)
            ->onQueue($this->config['queue'] ?? null);

        $job->delay($delaySeconds > 0 ? $delaySeconds : null);

        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    /**
     * A `sync` connection runs the job inline and ignores its delay: every
     * attempt would fire back-to-back inside the turn's own worker.
     */
    public function queueable(): bool
    {
        $config = $this->container->make('config');
        $connection = $this->config['connection'] ?? $config->get('queue.default');

        return $config->get("queue.connections.{$connection}.driver") !== 'sync';
    }

    /**
     * @param  list<string>  $unresolved
     */
    public function gaveUp(UsageEvent|int $turn, array $unresolved, int $attempts, ?string $reason = null, ?int $lastStatus = null): void
    {
        if ($unresolved === []) {
            return;
        }

        Log::warning('ai-kit: interrupted generations were never priced by OpenRouter', array_filter([
            'usage_event_id' => $turn instanceof UsageEvent ? $turn->getKey() : $turn,
            'generation_ids' => $unresolved,
            'attempts' => $attempts,
            'last_status' => $lastStatus,
            'reason' => $reason,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  list<string>  $unresolved
     */
    public function unavailable(UsageEvent|int $turn, array $unresolved, GenerationCostUnavailable $e): void
    {
        Log::error('ai-kit: interrupted generations cannot be priced: '.$e->getMessage(), [
            'usage_event_id' => $turn instanceof UsageEvent ? $turn->getKey() : $turn,
            'generation_ids' => $unresolved,
        ]);
    }

    /**
     * @param  array<string, float>  $resolved
     */
    protected function settle(ResolveInterruptedSpend $job, array $resolved): void
    {
        try {
            $this->finish($job->turnUsageId, $resolved, $job->pendingGenerationIds, $job->turnId, $job->meta);
        } catch (Throwable $e) {
            // A listener failed: hand the settlement to the queue to retry.
            report($e);

            rescue(fn () => $this->queue($job, 0));
        }
    }

    /**
     * @param  array<string, float>  $resolved
     * @param  list<string>  $pending
     * @param  array<string, mixed>  $meta
     */
    protected function settleQuietly(UsageEvent $turn, array $resolved, array $pending, ?string $turnId, array $meta): void
    {
        rescue(fn () => $this->finish((int) $turn->getKey(), $resolved, $pending, $turnId, $meta));
    }

    protected function enabled(): bool
    {
        return (bool) ($this->config['resolve_interrupted'] ?? true);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function fingerprint(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return sha1(implode(',', $ids));
    }
}

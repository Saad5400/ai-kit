<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Streaming\TurnOutcome;
use Throwable;

/**
 * Settles the spend of a turn that did not complete — stopped by the user,
 * or failed — which no usage row ever meters (the usage module writes rows
 * for completed and paused turns only).
 *
 * Owner ruling 2026-09-28: a STOPPED turn is debited at the actual provider
 * cost up to the stop; a FAILED turn stays free, but its cost still counts
 * toward the daily budget. The kit does the pricing and the budget; the app
 * decides who pays in its {@see InterruptedSpendHandler}.
 *
 * Call {@see dispatchFor()} once at the end of every interrupted turn (a
 * stopped or failed {@see TurnOutcome}). It drains the
 * {@see SpendCollector} — so nothing of this turn reaches the next one on
 * the worker — and:
 *
 *  1. records what was already priced (the turn's completed steps, and an
 *     interrupted step whose usage frame arrived before the cut) on the
 *     budget, keyed per turn;
 *  2. prices the PENDING generations (cut off before their final chunk)
 *     through {@see GenerationCostResolver} inside a short bounded window,
 *     recording each on the budget keyed per generation id;
 *  3. hands the total to the handler right away when everything priced,
 *     else queues {@see ResolveInterruptedSpend} for the rest.
 *
 * With nothing priced and nothing pending it does nothing at all.
 */
class InterruptedSpend
{
    /**
     * @param  array<string, mixed>  $config  the ai-kit.spend config section
     */
    public function __construct(
        protected SpendCollector $spend,
        protected GenerationCostResolver $resolver,
        protected Container $container,
        protected Repository $cache,
        protected array $config = [],
    ) {}

    /**
     * The app entry point: settle the interrupted turn `$turnId`. `$context`
     * travels untouched to the handler (it must be queue-serialisable) —
     * put the payer and whether the turn was stopped or failed there.
     *
     * @param  array<string, mixed>  $context
     */
    public static function dispatchFor(string $turnId, array $context = []): InterruptedSpendReport
    {
        return app(static::class)->settle($turnId, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function settle(string $turnId, array $context = []): InterruptedSpendReport
    {
        $settledCost = max(0.0, $this->spend->totalCost());
        $settledIds = $this->spend->generationIds();
        $pending = $this->spend->pendingGenerationIds();

        // Drained before anything is queued: Laravel serialises Context into
        // every job payload, and this turn's spend must never be read again
        // — not by the next turn on this worker, not by a job.
        $this->spend->flush();

        if ($settledCost <= 0 && $pending === []) {
            return new InterruptedSpendReport;
        }

        if ($settledCost > 0) {
            $this->recordBudget('interrupted:settled:'.$turnId.':'.self::fingerprint($settledIds), $settledCost);
        }

        $allIds = self::union($settledIds, $pending);

        // Disabled: nothing is fetched or queued; what was already priced
        // is still recorded and handed over.
        $resolved = $pending !== [] && $this->enabled() ? $this->resolver->resolveMany($pending) : [];

        foreach ($resolved as $id => $cost) {
            $this->recordResolved($id, $cost);
        }

        $unresolved = $this->enabled() ? array_values(array_diff($pending, array_keys($resolved))) : [];

        $report = fn (bool $handled, bool $queued) => new InterruptedSpendReport(
            $settledCost, $settledIds, $pending, (float) array_sum($resolved), $unresolved, $handled, $queued,
        );

        if ($unresolved !== [] && $this->retryDelays() === []) {
            $this->gaveUp($turnId, $unresolved, 0);
        }

        if ($unresolved === [] || $this->retryDelays() === []) {
            try {
                return $report($this->finish($turnId, $settledCost, $settledIds, $resolved, $allIds, $context), false);
            } catch (Throwable $e) {
                // The end of the app's turn is no place for its own
                // handler's failure: report it and let the queue retry the
                // hand-over (nothing left to price, so it runs at once).
                report($e);

                $this->queue(new ResolveInterruptedSpend($turnId, [], $context, $resolved, $settledCost, $settledIds, $allIds, 1), 0);

                return $report(false, true);
            }
        }

        $this->queue(
            new ResolveInterruptedSpend($turnId, $unresolved, $context, $resolved, $settledCost, $settledIds, $allIds, 1),
            $this->retryDelays()[0],
        );

        return $report(false, true);
    }

    /**
     * Budget a generation the resolver priced — once per generation id, so a
     * retried job or a duplicate chain can never count it twice.
     */
    public function recordResolved(string $generationId, float $costUsd): void
    {
        if ($costUsd > 0) {
            $this->recordBudget('interrupted:generation:'.$generationId, $costUsd);
        }
    }

    /**
     * Hand the settled + resolved total to the app's handler, at most once
     * per turn id and generation set. Returns whether the handler ran. A
     * handler that throws releases the guard, so a retry can hand it over
     * again.
     *
     * @param  list<string>  $settledIds
     * @param  array<string, float>  $resolved
     * @param  list<string>  $allIds  every generation of the settlement (the guard's fingerprint)
     * @param  array<string, mixed>  $context
     */
    public function finish(string $turnId, float $settledCost, array $settledIds, array $resolved, array $allIds, array $context): bool
    {
        $cost = $settledCost + (float) array_sum($resolved);

        if ($cost <= 0) {
            return false;
        }

        $key = 'ai-kit:interrupted-spend:handled:'.$turnId.':'.self::fingerprint($allIds);

        if (! $this->cache->add($key, 1, now()->addDays(2))) {
            return false;
        }

        try {
            $this->container->make(InterruptedSpendHandler::class)->resolved(
                $turnId,
                $cost,
                self::union($settledIds, array_keys($resolved)),
                $context,
            );
        } catch (Throwable $e) {
            $this->cache->forget($key);

            throw $e;
        }

        return true;
    }

    /**
     * Seconds before each queued pricing attempt; its length is the number
     * of attempts.
     *
     * @return list<int>
     */
    public function retryDelays(): array
    {
        return array_values(array_map('intval', $this->config['retry_delays_seconds'] ?? [10, 30, 90, 180, 300]));
    }

    public function queue(ResolveInterruptedSpend $job, int $delaySeconds): void
    {
        $job->onConnection($this->config['connection'] ?? null)
            ->onQueue($this->config['queue'] ?? null);

        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    protected function enabled(): bool
    {
        return (bool) ($this->config['resolve_interrupted'] ?? true);
    }

    protected function recordBudget(string $key, float $usd): void
    {
        if (! ($this->config['record_budget'] ?? true) || ! $this->container->bound(BudgetGuard::class)) {
            return;
        }

        rescue(fn () => $this->container->make(BudgetGuard::class)->recordOnce($key, $usd));
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

    /**
     * @param  list<string>  ...$lists
     * @return list<string>
     */
    protected static function union(array ...$lists): array
    {
        return array_values(array_unique(array_merge(...$lists)));
    }

    /**
     * Log a settlement the queue gave up on.
     *
     * @param  list<string>  $unresolved
     */
    public function gaveUp(string $turnId, array $unresolved, int $attempts): void
    {
        Log::warning('ai-kit: interrupted generations were never priced by OpenRouter', [
            'turn_id' => $turnId,
            'generation_ids' => $unresolved,
            'attempts' => $attempts,
        ]);
    }
}

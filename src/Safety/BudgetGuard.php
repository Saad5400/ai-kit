<?php

namespace Saad\AiKit\Safety;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Saad\AiKit\Safety\Events\DailyBudgetExceeded;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;

/**
 * Daily USD spend gate. Unlike the KillSwitch it is automatic and
 * self-resetting: the counter is keyed by calendar day, so a tripped
 * budget re-opens at midnight without operator action.
 */
class BudgetGuard
{
    /**
     * Costs are accumulated as integer micro-dollars because cache
     * increments are only atomic for integers.
     */
    private const MICRO = 1_000_000;

    public function __construct(
        protected Repository $cache,
        protected Dispatcher $events,
        protected ?float $dailyLimit,
        protected ?string $timezone = null,
        protected ?SafetySettings $settings = null,
    ) {}

    /**
     * Record spend and return the day's running total in USD.
     */
    public function record(float $usd): float
    {
        if ($usd <= 0) {
            return $this->spentToday();
        }

        $key = $this->key();

        $this->cache->add($key, 0, now()->addDays(2));

        $total = $this->cache->increment($key, (int) round($usd * self::MICRO)) / self::MICRO;

        if ($this->limitReached($total) && $this->cache->add($this->key().':notified', 1, now()->addDays(2))) {
            $this->events->dispatch(new DailyBudgetExceeded($total, $this->limit()));
        }

        return $total;
    }

    public function spentToday(): float
    {
        return ((int) $this->cache->get($this->key(), 0)) / self::MICRO;
    }

    /**
     * The effective limit: the app's settings store when one is bound and
     * answers (a value <= 0 reads as exhausted outright), falling back to
     * the construction-time value when the store returns null — an explicit
     * cap must never be silently voided by a store that simply has no
     * budget configured. Null from both means unlimited.
     */
    public function limit(): ?float
    {
        return $this->settings?->dailyBudgetUsd() ?? $this->dailyLimit;
    }

    /**
     * Record spend exactly once per idempotency key (the turn's invocation
     * id) and return the day's running total. A replayed usage event or a
     * double-registered listener re-presents the same key and adds nothing.
     */
    public function recordOnce(string $key, float $usd): float
    {
        if (! $this->cache->add('ai-kit:budget:seen:'.$key, 1, now()->addDays(2))) {
            return $this->spentToday();
        }

        return $this->record($usd);
    }

    public function remaining(): ?float
    {
        $limit = $this->limit();

        return $limit === null
            ? null
            : max(0.0, $limit - $this->spentToday());
    }

    public function exceeded(): bool
    {
        return $this->limitReached($this->spentToday());
    }

    /**
     * @throws BudgetExceededException
     */
    public function enforce(): void
    {
        if ($this->exceeded()) {
            throw new BudgetExceededException(
                $this->spentToday(),
                $this->limit(),
                $this->secondsUntilTomorrow(),
            );
        }
    }

    protected function limitReached(float $total): bool
    {
        $limit = $this->limit();

        // A limit <= 0 always reads as reached — an operator zeroing the
        // budget uses it as a kill switch for budget-gated surfaces.
        return $limit !== null && $total >= $limit;
    }

    protected function key(): string
    {
        return 'ai-kit:budget:'.now($this->timezone)->format('Y-m-d');
    }

    protected function secondsUntilTomorrow(): int
    {
        return (int) now($this->timezone)->diffInSeconds(
            now($this->timezone)->addDay()->startOfDay(),
        );
    }
}

<?php

namespace Saad\AiKit\Usage;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Read-side API over the usage events — the numbers budget checks and
 * OpenRouter reconciliation are built on. Only completed turns (ok/paused)
 * carry cost; failed_over rows are excluded from spend sums by having none.
 */
class TurnSpend
{
    /**
     * @return Collection<int, UsageEvent>
     */
    public function forInvocation(string $invocationId): Collection
    {
        return UsageEvent::query()
            ->where('invocation_id', $invocationId)
            ->orderBy('id')
            ->get();
    }

    public function todayUsd(?string $feature = null, ?string $timezone = null): float
    {
        return $this->usdBetween(
            now($timezone)->startOfDay(),
            now($timezone)->endOfDay(),
            $feature,
        );
    }

    public function usdBetween(DateTimeInterface $from, DateTimeInterface $to, ?string $feature = null): float
    {
        return (float) UsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($feature !== null, fn ($query) => $query->where('feature', $feature))
            ->sum('cost_usd');
    }
}

<?php

namespace Saad\AiKit\Usage;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Read-side API over the usage events — the numbers budget checks and
 * OpenRouter reconciliation are built on. Cost is carried by completed
 * turns (ok/paused), by interrupted ones (stopped/failed: the steps they
 * completed) and by `resolved` delta rows (their cut-off generations,
 * priced later, sharing the turn's invocation id); failed_over rows are
 * excluded from spend sums by having none.
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

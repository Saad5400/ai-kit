<?php

namespace Saad\AiKit\Usage\Events;

use Saad\AiKit\Usage\UsageEvent;

/**
 * The late half of a turn's spend: the price of generations a stop or a
 * mid-stream failure cut off before their final chunk carried `usage.cost`,
 * fetched afterwards from OpenRouter. Fires AT MOST ONCE per settled run (usage row), only with
 * a cost above zero — normally from the queued ResolveInterruptedSpend job,
 * after the turn's own job ended (inline, at the end of the turn, only when
 * `spend.sync_window_seconds` > 0 priced everything there) — and never
 * folded into the turn's {@see TurnUsageRecorded} (whose debit may already
 * have landed under `debit:turn:{id}`).
 *
 * `$usage` is the delta's own usage row (status `resolved`, same invocation
 * id, `cost_source` = `generation_lookup`, `context.resolves` = the turn
 * row's id) — so usage totals and OpenRouter reconciliation include it.
 * `$turn` is the turn's row: `stopped` / `failed`, or `ok` / `paused` for a
 * turn that completed after all (a failover attempt or a sub-agent was cut
 * off along the way). The daily budget has already been recorded (keyed per
 * generation id) by the time this fires.
 *
 * Debit when {@see billable()} (anything but a failed turn), idempotently,
 * under {@see debitKey()} — separate from the turn's main debit.
 */
class InterruptedSpendResolved
{
    /**
     * @param  list<string>  $generationIds  the generations the cost covers
     * @param  array<string, mixed>  $meta  what the app passed to TurnRunner::run() / TurnContext::beginTurn()
     */
    public function __construct(
        public UsageEvent $usage,
        public UsageEvent $turn,
        public ?string $turnId,
        public float $costUsd,
        public array $generationIds,
        public array $meta = [],
    ) {}

    public function stopped(): bool
    {
        return $this->turn->status === TurnUsageRecorded::STOPPED;
    }

    public function failed(): bool
    {
        return $this->turn->status === TurnUsageRecorded::FAILED;
    }

    /**
     * Owner ruling 2026-09-28: a failed turn is free; everything else pays
     * what it actually cost.
     */
    public function billable(): bool
    {
        return ! $this->failed();
    }

    /**
     * `debit:turn:{turnId}:interrupted:{invocationId}` — the app's turn id
     * when the turn ran under TurnRunner (so it sits next to the main
     * `debit:turn:{turnId}`), else the invocation id; suffixed with the
     * settled run's invocation id, because one turn can settle more than
     * once (a resume leg, a completed turn's cut-off sub-step) and each
     * settlement is its own debit.
     */
    public function debitKey(): string
    {
        return 'debit:turn:'.($this->turnId ?? $this->turn->invocation_id).':interrupted:'.$this->turn->invocation_id;
    }
}

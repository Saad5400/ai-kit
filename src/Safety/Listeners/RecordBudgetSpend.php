<?php

namespace Saad\AiKit\Safety\Listeners;

use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;

/**
 * Feeds each metered turn's cost into the BudgetGuard so spentToday()
 * tracks real provider spend with no app code involved. Safety accounting
 * must never break a turn: failures are reported and swallowed.
 */
class RecordBudgetSpend
{
    public function __construct(protected BudgetGuard $budget) {}

    public function handle(TurnUsageRecorded $event): void
    {
        if (! config('ai-kit.safety.record_spend_from_usage', true)) {
            return;
        }

        rescue(function () use ($event) {
            $cost = $event->usage->cost_usd;

            if ($cost !== null) {
                $this->budget->record((float) $cost);
            }
        });
    }
}

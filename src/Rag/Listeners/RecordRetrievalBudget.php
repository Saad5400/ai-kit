<?php

namespace Saad\AiKit\Rag\Listeners;

use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Safety\BudgetGuard;

/** Feeds a retrieval call's provider-reported cost into the daily BudgetGuard. */
class RecordRetrievalBudget
{
    public function __construct(private readonly BudgetGuard $budget) {}

    public function handle(RetrievalSpend $event): void
    {
        if (config('ai-kit.rag.record_budget', true) !== true || $event->costUsd === null) {
            return;
        }

        $this->budget->record($event->costUsd);
    }
}

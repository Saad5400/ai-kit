<?php

namespace Saad\AiKit\Rag\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One finished retrieval call — an embedding batch or a rerank — and what it
 * cost. `costUsd` is whatever the provider reported (`usage.cost`) and null
 * when it reported nothing; it is never estimated from a price table
 * (DECISIONS.md #26c). When the safety module is on and
 * `ai-kit.rag.record_budget` is true, the kit adds it to the daily
 * BudgetGuard; an app that meters retrieval per feature listens here.
 */
final class RetrievalSpend
{
    use Dispatchable;

    /**
     * @param  'embeddings'|'rerank'  $lane
     */
    public function __construct(
        public readonly string $lane,
        public readonly string $model,
        public readonly int $requests,
        public readonly int $inputs,
        public readonly int $tokens,
        public readonly ?float $costUsd,
        public readonly int $durationMs,
        public readonly bool $failed = false,
    ) {}
}

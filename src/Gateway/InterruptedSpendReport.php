<?php

namespace Saad\AiKit\Gateway;

/**
 * What {@see InterruptedSpend::dispatchFor()} found and did. Informational:
 * the money moves through the {@see InterruptedSpendHandler}, never through
 * this object, so an app that ignores it still debits correctly.
 */
final class InterruptedSpendReport
{
    /**
     * @param  list<string>  $settledGenerationIds  priced before the dispatch (completed steps, or a usage frame ahead of the cut)
     * @param  list<string>  $pendingGenerationIds  cut off before their cost arrived
     * @param  list<string>  $unresolvedGenerationIds  still unpriced after the fast path — left to the queued job
     */
    public function __construct(
        public readonly float $settledCostUsd = 0.0,
        public readonly array $settledGenerationIds = [],
        public readonly array $pendingGenerationIds = [],
        public readonly float $resolvedCostUsd = 0.0,
        public readonly array $unresolvedGenerationIds = [],
        public readonly bool $handled = false,
        public readonly bool $queued = false,
    ) {}

    /**
     * The spend known right now: settled plus what the fast path priced.
     */
    public function knownCostUsd(): float
    {
        return $this->settledCostUsd + $this->resolvedCostUsd;
    }

    /**
     * Nothing to settle: no unmetered cost and no pending generation.
     */
    public function empty(): bool
    {
        return $this->settledCostUsd <= 0 && $this->pendingGenerationIds === [];
    }
}

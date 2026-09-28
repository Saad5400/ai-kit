<?php

namespace Saad\AiKit\Gateway;

/**
 * Receives the exact provider cost and generation ids the gateway captures
 * from OpenRouter responses. The streamed/non-streamed split is deliberate:
 * helper calls (vision pre-pass, title generation, routing) must be billed
 * as their own usage events, never folded into a streamed assistant turn.
 *
 * The read side is part of the contract because the usage module records
 * turns from what the collector accumulated; a collector that cannot be
 * read back cannot be metered.
 *
 * PENDING generations (0.14.1) are steps a stop or a mid-stream failure cut
 * off after OpenRouter assigned the generation id but before its final
 * chunk carried `usage.cost`. OpenRouter still bills them; their price is
 * fetched afterwards by {@see InterruptedSpend}, which drains them.
 */
interface SpendCollector
{
    public function recordCost(float $usd, bool $streamed): void;

    public function recordGenerationId(string $generationId, bool $streamed): void;

    /**
     * Total captured cost in USD. Streamed, non-streamed, or both.
     */
    public function totalCost(?bool $streamed = null): float;

    /**
     * Captured generation ids, deduplicated.
     *
     * @return list<string>
     */
    public function generationIds(?bool $streamed = null): array;

    /**
     * Record a generation that was interrupted before its cost arrived.
     */
    public function recordPendingGeneration(string $generationId): void;

    /**
     * Interrupted generations still waiting for a price, deduplicated. An id
     * also recorded through {@see recordGenerationId()} (its step completed
     * after all) is never pending, so it can never be priced twice.
     *
     * @return list<string>
     */
    public function pendingGenerationIds(): array;

    /**
     * Clear all captured values, pending generations included.
     */
    public function flush(): void;
}

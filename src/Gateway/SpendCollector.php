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
     * Clear all captured values.
     */
    public function flush(): void;
}

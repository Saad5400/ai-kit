<?php

namespace Saad\AiKit\Gateway;

/**
 * Receives the exact provider cost and generation ids the gateway captures
 * from OpenRouter responses. The streamed/non-streamed split is deliberate:
 * helper calls (vision pre-pass, title generation, routing) must be billed
 * as their own usage events, never folded into a streamed assistant turn.
 */
interface SpendCollector
{
    public function recordCost(float $usd, bool $streamed): void;

    public function recordGenerationId(string $generationId, bool $streamed): void;
}

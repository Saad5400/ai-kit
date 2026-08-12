<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Support\Facades\Context;

/**
 * Default collector: pushes onto Laravel Context lists, matching the keys
 * the three apps' forks already established. Callers own the protocol of
 * clearing before a turn and draining after it — flush()/drain() cover both
 * sides. The prefix is configurable because s-grade shipped with
 * "assistant." while uqucc/catodemy use "ai.".
 */
class ContextSpendCollector implements SpendCollector
{
    public function __construct(protected string $prefix = 'ai') {}

    public function recordCost(float $usd, bool $streamed): void
    {
        Context::push($streamed ? $this->costsKey() : $this->nonStreamCostsKey(), $usd);
    }

    public function recordGenerationId(string $generationId, bool $streamed): void
    {
        Context::push($streamed ? $this->generationIdsKey() : $this->nonStreamGenerationIdsKey(), $generationId);
    }

    /**
     * Total captured cost in USD. Streamed, non-streamed, or both.
     */
    public function totalCost(?bool $streamed = null): float
    {
        $keys = match ($streamed) {
            true => [$this->costsKey()],
            false => [$this->nonStreamCostsKey()],
            null => [$this->costsKey(), $this->nonStreamCostsKey()],
        };

        return array_sum(array_merge(...array_map(
            fn (string $key): array => Context::get($key, []),
            $keys,
        )));
    }

    /**
     * Captured generation ids, deduplicated.
     *
     * @return list<string>
     */
    public function generationIds(?bool $streamed = null): array
    {
        $keys = match ($streamed) {
            true => [$this->generationIdsKey()],
            false => [$this->nonStreamGenerationIdsKey()],
            null => [$this->generationIdsKey(), $this->nonStreamGenerationIdsKey()],
        };

        return array_values(array_unique(array_merge(...array_map(
            fn (string $key): array => Context::get($key, []),
            $keys,
        ))));
    }

    /**
     * Clear all captured values. Call before starting a turn so a previous
     * call in the same request never leaks into the next capture.
     */
    public function flush(): void
    {
        Context::forget([
            $this->costsKey(),
            $this->nonStreamCostsKey(),
            $this->generationIdsKey(),
            $this->nonStreamGenerationIdsKey(),
        ]);
    }

    /**
     * Read everything and clear: [totalCostUsd, generationIds].
     *
     * @return array{0: float, 1: list<string>}
     */
    public function drain(?bool $streamed = null): array
    {
        $result = [$this->totalCost($streamed), $this->generationIds($streamed)];

        $this->flush();

        return $result;
    }

    public function costsKey(): string
    {
        return "{$this->prefix}.openrouter_costs";
    }

    public function nonStreamCostsKey(): string
    {
        return "{$this->prefix}.openrouter_non_stream_costs";
    }

    public function generationIdsKey(): string
    {
        return "{$this->prefix}.openrouter_generation_ids";
    }

    public function nonStreamGenerationIdsKey(): string
    {
        return "{$this->prefix}.openrouter_non_stream_generation_ids";
    }
}

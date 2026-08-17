<?php

namespace Saad\AiKit\Testing;

use PHPUnit\Framework\Assert;
use Saad\AiKit\Gateway\SpendCollector;

/**
 * In-memory {@see SpendCollector} for tests: bind it over the contract and
 * every cost/generation-id the gateway (or code under test) records is
 * captured for assertion instead of accumulating in request context.
 */
class FakeSpendCollector implements SpendCollector
{
    /** @var list<array{usd: float, streamed: bool}> */
    protected array $costs = [];

    /** @var list<array{id: string, streamed: bool}> */
    protected array $generationIds = [];

    public function recordCost(float $usd, bool $streamed): void
    {
        $this->costs[] = ['usd' => $usd, 'streamed' => $streamed];
    }

    public function recordGenerationId(string $generationId, bool $streamed): void
    {
        $this->generationIds[] = ['id' => $generationId, 'streamed' => $streamed];
    }

    public function totalCost(?bool $streamed = null): float
    {
        return array_sum(array_map(
            fn (array $cost): float => $cost['usd'],
            $this->filtered($this->costs, $streamed),
        ));
    }

    public function generationIds(?bool $streamed = null): array
    {
        return array_values(array_unique(array_map(
            fn (array $generation): string => $generation['id'],
            $this->filtered($this->generationIds, $streamed),
        )));
    }

    public function flush(): void
    {
        $this->costs = [];
        $this->generationIds = [];
    }

    public function assertTotalCost(float $usd, ?bool $streamed = null): void
    {
        Assert::assertEqualsWithDelta($usd, $this->totalCost($streamed), 0.000001, sprintf(
            'Expected a total captured cost of $%.6f but the collector holds $%.6f.',
            $usd,
            $this->totalCost($streamed),
        ));
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->costs, 'Expected no costs to be recorded, but some were.');
        Assert::assertSame([], $this->generationIds, 'Expected no generation ids to be recorded, but some were.');
    }

    /**
     * @param  list<array{streamed: bool, ...}>  $entries
     * @return list<array{streamed: bool, ...}>
     */
    protected function filtered(array $entries, ?bool $streamed): array
    {
        if ($streamed === null) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['streamed'] === $streamed,
        ));
    }
}

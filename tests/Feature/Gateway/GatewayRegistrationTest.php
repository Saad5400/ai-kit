<?php

use Laravel\Ai\Ai;
use Saad\AiKit\Gateway\ContextSpendCollector;
use Saad\AiKit\Gateway\ReasoningOpenRouterGateway;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Tests\Support\GatewayFactory;

it('binds the spend collector with the configured prefix', function () {
    $collector = $this->app->make(SpendCollector::class);

    expect($collector)->toBeInstanceOf(ContextSpendCollector::class)
        ->and($collector->costsKey())->toBe('ai.openrouter_costs')
        ->and($collector->nonStreamGenerationIdsKey())->toBe('ai.openrouter_non_stream_generation_ids');
});

it('swaps the openrouter text gateway for the canonical one', function () {
    $provider = Ai::textProvider('openrouter');

    expect($provider->textGateway())->toBeInstanceOf(ReasoningOpenRouterGateway::class);
});

it('extracts cost only when positive and numeric', function () {
    $gateway = GatewayFactory::gateway();

    expect($gateway->extractOpenRouterCost(['usage' => ['cost' => 0.02]]))->toBe(0.02)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => '0.03']]))->toBe(0.03)
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 0]]))->toBeNull()
        ->and($gateway->extractOpenRouterCost(['usage' => ['cost' => 'abc']]))->toBeNull()
        ->and($gateway->extractOpenRouterCost(['usage' => []]))->toBeNull()
        ->and($gateway->extractOpenRouterCost([]))->toBeNull();
});

it('drains totals and deduplicated ids from the collector', function () {
    $collector = $this->app->make(SpendCollector::class);

    $collector->recordCost(0.01, streamed: true);
    $collector->recordCost(0.02, streamed: true);
    $collector->recordCost(0.005, streamed: false);
    $collector->recordGenerationId('gen-1', streamed: true);
    $collector->recordGenerationId('gen-1', streamed: true);
    $collector->recordGenerationId('gen-2', streamed: false);

    expect($collector->totalCost(streamed: true))->toEqualWithDelta(0.03, 1e-9)
        ->and($collector->totalCost())->toEqualWithDelta(0.035, 1e-9)
        ->and($collector->generationIds())->toBe(['gen-1', 'gen-2']);

    [$total, $ids] = $collector->drain();

    expect($total)->toEqualWithDelta(0.035, 1e-9)
        ->and($ids)->toBe(['gen-1', 'gen-2'])
        ->and($collector->totalCost())->toBe(0.0)
        ->and($collector->generationIds())->toBe([]);
});

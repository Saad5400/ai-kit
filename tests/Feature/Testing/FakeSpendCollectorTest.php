<?php

use Saad\AiKit\Testing\FakeSpendCollector;

it('captures costs and generation ids with the streamed split intact', function () {
    $collector = new FakeSpendCollector;

    $collector->recordCost(0.01, streamed: true);
    $collector->recordCost(0.002, streamed: false);
    $collector->recordGenerationId('gen-1', streamed: true);
    $collector->recordGenerationId('gen-2', streamed: false);
    $collector->recordGenerationId('gen-1', streamed: true);

    expect($collector->totalCost())->toBe(0.012)
        ->and($collector->totalCost(streamed: true))->toBe(0.01)
        ->and($collector->totalCost(streamed: false))->toBe(0.002)
        ->and($collector->generationIds())->toBe(['gen-1', 'gen-2'])
        ->and($collector->generationIds(streamed: false))->toBe(['gen-2']);
});

it('flushes everything', function () {
    $collector = new FakeSpendCollector;
    $collector->recordCost(1.0, streamed: true);
    $collector->recordGenerationId('gen-1', streamed: true);

    $collector->flush();

    $collector->assertNothingRecorded();
    expect($collector->totalCost())->toBe(0.0);
});

it('asserts the captured total', function () {
    $collector = new FakeSpendCollector;
    $collector->recordCost(0.1, streamed: true);
    $collector->recordCost(0.2, streamed: false);

    $collector->assertTotalCost(0.3);
    $collector->assertTotalCost(0.1, streamed: true);
});

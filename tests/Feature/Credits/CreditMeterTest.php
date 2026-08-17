<?php

use Saad\AiKit\Credits\ChargeResult;
use Saad\AiKit\Credits\CreditCalculator;
use Saad\AiKit\Credits\CreditMeter;
use Saad\AiKit\Testing\FakeCreditDebitor;

function meter(?FakeCreditDebitor $debitor = null): array
{
    $debitor ??= new FakeCreditDebitor;

    return [new CreditMeter(new CreditCalculator, $debitor), $debitor];
}

it('charges a tool-using turn under debit:turn:{id} with cost meta', function () {
    [$meter, $debitor] = meter();

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01, usedTools: true);

    expect($result->isCharged())->toBeTrue()
        ->and($result->creditsCharged)->toBe(28)
        ->and($result->costSource)->toBe('provider_usage');

    $debitor->assertDebited(28, 'debit:turn:turn-1');
    expect($debitor->debits[0]['meta'])->toMatchArray([
        'turn_id' => 'turn-1',
        'cost_usd' => 0.01,
        'cost_source' => 'provider_usage',
    ]);
});

it('prefers provider cost, falling back to the estimate as estimated', function () {
    [$meter, $debitor] = meter();

    $fromProvider = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01, estimatedCostUsd: 0.5);
    $fromEstimate = $meter->chargeTurn('user:1', 'turn-2', providerCostUsd: null, estimatedCostUsd: 0.01);

    expect($fromProvider->costUsd)->toBe(0.01)
        ->and($fromEstimate->costSource)->toBe('estimated')
        ->and($fromEstimate->costUsd)->toBe(0.01)
        ->and($debitor->debits)->toHaveCount(2);
});

it('waives when no cost resolves at all', function () {
    [$meter, $debitor] = meter();

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: null, estimatedCostUsd: null);

    expect($result->isWaived())->toBeTrue()
        ->and($result->waiveReason)->toBe('no_cost');

    $debitor->assertNothingDebited();
});

it('always waives a planning turn, still reporting the cost', function () {
    [$meter, $debitor] = meter();

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.05, usedTools: true, planOnly: true);

    expect($result->waiveReason)->toBe('plan_only')
        ->and($result->costUsd)->toBe(0.05);

    $debitor->assertNothingDebited();
});

it('waives cheap tool-less chit-chat under the USD ceiling, and only that', function () {
    [$meter, $debitor] = meter();

    $cheap = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.0005, usedTools: false);
    $tooled = $meter->chargeTurn('user:1', 'turn-2', providerCostUsd: 0.0005, usedTools: true);
    $attached = $meter->chargeTurn('user:1', 'turn-3', providerCostUsd: 0.0005, usedTools: false, hasAttachments: true);
    $pricey = $meter->chargeTurn('user:1', 'turn-4', providerCostUsd: 0.002, usedTools: false);

    expect($cheap->waiveReason)->toBe('free_turn')
        ->and($tooled->isCharged())->toBeTrue()
        ->and($attached->isCharged())->toBeTrue()
        ->and($pricey->isCharged())->toBeTrue()
        ->and($debitor->debits)->toHaveCount(3);
});

it('a ceiling of zero disables the chit-chat waiver', function () {
    config()->set('ai-kit.credits.free_turn_max_cost_usd', 0);
    [$meter] = meter();

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.0001, usedTools: false);

    expect($result->isCharged())->toBeTrue();
});

it('converts a duplicate idempotency key into an already-charged no-op', function () {
    [$meter, $debitor] = meter();

    $first = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01);
    $retry = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01);

    expect($first->isCharged())->toBeTrue()
        ->and($retry->status)->toBe(ChargeResult::STATUS_ALREADY_CHARGED)
        ->and($retry->creditsCharged)->toBe(28)
        ->and($debitor->debits)->toHaveCount(1);
});

it('passes the write-off through when the balance clamps at zero', function () {
    [$meter] = meter($debitor = new FakeCreditDebitor(balance: 10));

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01);

    expect($result->creditsCharged)->toBe(10)
        ->and($result->writeOff)->toBe(18);
});

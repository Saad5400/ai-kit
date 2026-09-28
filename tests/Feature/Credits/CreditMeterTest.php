<?php

use Saad\AiKit\Credits\ChargeResult;
use Saad\AiKit\Credits\CreditCalculator;
use Saad\AiKit\Credits\CreditMeter;
use Saad\AiKit\Testing\FakeCreditDebitor;
use Saad\AiKit\Usage\Events\InterruptedSpendResolved;
use Saad\AiKit\Usage\UsageEvent;

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

it('bills the provider-reported cost and waives an unreported one rather than estimating', function () {
    [$meter, $debitor] = meter();

    $reported = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: 0.01);
    $unreported = $meter->chargeTurn('user:1', 'turn-2', providerCostUsd: null);

    expect($reported->costUsd)->toBe(0.01)
        ->and($reported->costSource)->toBe('provider_usage')
        ->and($unreported->isWaived())->toBeTrue()
        ->and($unreported->waiveReason)->toBe('no_cost')
        ->and($debitor->debits)->toHaveCount(1);
});

it('waives when no cost resolves at all', function () {
    [$meter, $debitor] = meter();

    $result = $meter->chargeTurn('user:1', 'turn-1', providerCostUsd: null);

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

function resolvedSpend(string $turnStatus, float $cost = 0.0002): InterruptedSpendResolved
{
    $turn = new UsageEvent(['invocation_id' => 'inv-1', 'status' => $turnStatus]);

    return new InterruptedSpendResolved(new UsageEvent(['status' => 'resolved']), $turn, 'turn-1', $cost, ['gen-1']);
}

it('charges the late-priced cut-off step of a stopped turn under its own key, with no free-turn waiver', function () {
    [$meter, $debitor] = meter();

    // Under the chit-chat ceiling: a stopped cheap answer is exactly what must pay.
    $result = $meter->chargeResolved('user:1', resolvedSpend('stopped'));

    expect($result->isCharged())->toBeTrue()
        ->and($result->costSource)->toBe('generation_lookup');

    $debitor->assertDebited($result->creditsCharged, 'debit:turn:turn-1:interrupted');
    expect($debitor->debits[0]['meta'])->toMatchArray(['turn_id' => 'turn-1', 'generation_ids' => ['gen-1']]);

    expect($meter->chargeResolved('user:1', resolvedSpend('stopped'))->status)->toBe(ChargeResult::STATUS_ALREADY_CHARGED);
});

it('waives the late-priced spend of a failed turn', function () {
    [$meter, $debitor] = meter();

    expect($meter->chargeResolved('user:1', resolvedSpend('failed'))->waiveReason)->toBe('failed_turn');

    $debitor->assertNothingDebited();
});

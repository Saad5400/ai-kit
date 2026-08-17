<?php

use Saad\AiKit\Credits\CreditCalculator;

it('converts cost to credits with margin at consumption, always rounding up', function () {
    $calculator = new CreditCalculator;

    // 0.01 * 1.10 / 0.0004 = 27.5 → 28: ceil() never erodes margin.
    expect($calculator->creditsForCostUsd(0.01))->toBe(28)
        ->and($calculator->creditsForCostUsd(0.01, margin: 0.0))->toBe(25)
        ->and($calculator->creditsForCostUsd(0.0))->toBe(0)
        ->and($calculator->creditsForCostUsd(-1.0))->toBe(0);
});

it('reads its policy from config', function () {
    config()->set('ai-kit.credits.margin', 0.20);
    config()->set('ai-kit.credits.credit_unit_usd', 0.001);

    $calculator = new CreditCalculator;

    // 0.01 * 1.20 / 0.001 = 12
    expect($calculator->creditsForCostUsd(0.01))->toBe(12)
        ->and($calculator->margin())->toBe(0.20);
});

it('prices package floors and message estimates', function () {
    $calculator = new CreditCalculator;

    // 1000 * 0.0004 * 3.75 = 1.5 SAR
    expect($calculator->packageFloorSar(1000))->toBe(1.5)
        ->and($calculator->messagesFor(95))->toBe(9)
        ->and($calculator->messagesFor(-5))->toBe(0);
});

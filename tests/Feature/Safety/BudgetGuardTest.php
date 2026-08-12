<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\Events\DailyBudgetExceeded;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;

function makeGuard(?float $limit): BudgetGuard
{
    config()->set('ai-kit.safety.daily_usd_limit', $limit);

    return new BudgetGuard(
        app('cache')->store(),
        app('events'),
        $limit,
    );
}

afterEach(fn () => Carbon::setTestNow());

it('accumulates spend with sub-cent precision', function () {
    $guard = makeGuard(null);

    $guard->record(0.0123);
    $total = $guard->record(0.0456);

    expect($total)->toEqualWithDelta(0.0579, 0.000001)
        ->and($guard->spentToday())->toEqualWithDelta(0.0579, 0.000001);
});

it('never exceeds without a limit', function () {
    $guard = makeGuard(null);

    $guard->record(999.0);

    expect($guard->exceeded())->toBeFalse()
        ->and($guard->remaining())->toBeNull();

    $guard->enforce();
});

it('trips at the daily limit and reports remaining budget', function () {
    $guard = makeGuard(10.0);

    $guard->record(6.0);

    expect($guard->exceeded())->toBeFalse()
        ->and($guard->remaining())->toEqualWithDelta(4.0, 0.000001);

    $guard->record(4.5);

    expect($guard->exceeded())->toBeTrue()
        ->and($guard->remaining())->toEqualWithDelta(0.0, 0.000001);

    expect(fn () => $guard->enforce())->toThrow(BudgetExceededException::class);
});

it('dispatches the exceeded event exactly once per day', function () {
    Event::fake([DailyBudgetExceeded::class]);

    $guard = makeGuard(1.0);
    $guard->record(0.6);
    $guard->record(0.6);
    $guard->record(0.6);

    Event::assertDispatchedTimes(DailyBudgetExceeded::class, 1);
});

it('resets at midnight', function () {
    Carbon::setTestNow('2026-08-12 22:00:00');

    $guard = makeGuard(1.0);
    $guard->record(2.0);

    expect($guard->exceeded())->toBeTrue();

    Carbon::setTestNow('2026-08-13 00:00:01');

    expect($guard->spentToday())->toEqualWithDelta(0.0, 0.000001)
        ->and($guard->exceeded())->toBeFalse();
});

it('reports retry-after as seconds until the next day', function () {
    Carbon::setTestNow('2026-08-12 23:00:00');

    $guard = makeGuard(1.0);
    $guard->record(2.0);

    try {
        $guard->enforce();
        $this->fail('Expected BudgetExceededException');
    } catch (BudgetExceededException $e) {
        expect($e->retryAfterSeconds())->toBe(3600)
            ->and($e->userFacingReason())->toBe(__('ai-kit::safety.budget_exceeded'));
    }
});

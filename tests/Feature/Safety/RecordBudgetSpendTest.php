<?php

use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;
use Saad\AiKit\Safety\TurnGuard;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Saad\AiKit\Usage\UsageEvent;

function recordedTurn(?float $costUsd): TurnUsageRecorded
{
    return new TurnUsageRecorded(new UsageEvent(['cost_usd' => $costUsd]));
}

it('feeds recorded turn costs into the budget guard', function () {
    event(recordedTurn(0.25));
    event(recordedTurn(0.5));

    expect(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.75, 0.000001);
});

it('ignores turns without a cost', function () {
    event(recordedTurn(null));

    expect(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.0, 0.000001);
});

it('can be disabled at runtime by config', function () {
    config()->set('ai-kit.safety.record_spend_from_usage', false);

    event(recordedTurn(1.0));

    expect(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.0, 0.000001);
});

it('metered spend trips the turn guard end to end', function () {
    config()->set('ai-kit.safety.daily_usd_limit', 1.0);

    event(recordedTurn(1.5));

    expect(fn () => app(TurnGuard::class)->check())->toThrow(BudgetExceededException::class);
});

it('never lets a recording failure escape the event', function () {
    $this->app->instance(BudgetGuard::class, new class(app('cache')->store(), app('events'), null) extends BudgetGuard
    {
        public function record(float $usd): float
        {
            throw new RuntimeException('safety store down');
        }
    });

    event(recordedTurn(1.0));

    expect(true)->toBeTrue();
});

it('counts a replayed usage event only once', function () {
    $usage = new UsageEvent(['cost_usd' => 1.0, 'invocation_id' => 'inv-1']);

    event(new TurnUsageRecorded($usage));
    event(new TurnUsageRecorded($usage));

    expect(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(1.0, 0.000001);
});

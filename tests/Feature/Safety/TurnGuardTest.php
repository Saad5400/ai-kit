<?php

use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;
use Saad\AiKit\Safety\Exceptions\TooManyConcurrentTurnsException;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\TurnConcurrencyLimiter;
use Saad\AiKit\Safety\TurnGuard;

beforeEach(function () {
    $this->guard = $this->app->make(TurnGuard::class);
});

it('passes when nothing is engaged', function () {
    $this->guard->check();
    $this->guard->check('chat', 'user:1');

    expect($this->guard->allowed())->toBeTrue()
        ->and($this->guard->allowed('chat', 'user:1'))->toBeTrue();
});

it('refuses when the kill switch is engaged', function () {
    app(KillSwitch::class)->engage(reason: 'incident');

    expect(fn () => $this->guard->check())->toThrow(AiKilledException::class)
        ->and($this->guard->allowed('chat'))->toBeFalse();
});

it('refuses a scope disabled in settings while allowing others', function () {
    config()->set('ai-kit.safety.features', ['admin' => false]);

    $this->guard->check('chat');

    expect(fn () => $this->guard->check('admin'))->toThrow(AiKilledException::class);
});

it('refuses when the daily budget is exhausted', function () {
    config()->set('ai-kit.safety.daily_usd_limit', 1.0);
    app(BudgetGuard::class)->record(1.5);

    expect(fn () => $this->guard->check())->toThrow(BudgetExceededException::class)
        ->and($this->guard->allowed())->toBeFalse();
});

it('refuses an owner at the concurrency cap, but only when an owner is given', function () {
    $limiter = app(TurnConcurrencyLimiter::class);

    foreach (range(1, config('ai-kit.safety.max_concurrent_turns')) as $i) {
        $limiter->acquire('user:1');
    }

    expect(fn () => $this->guard->check(owner: 'user:1'))->toThrow(TooManyConcurrentTurnsException::class);

    $this->guard->check();
    $this->guard->check(owner: 'user:2');
});

it('check never reserves a concurrency slot', function () {
    $this->guard->check(owner: 'user:1');

    expect(app(TurnConcurrencyLimiter::class)->inFlight('user:1'))->toBe(0);
});

it('run holds a slot for the callback and releases it after', function () {
    $limiter = app(TurnConcurrencyLimiter::class);

    $result = $this->guard->run(function () use ($limiter) {
        expect($limiter->inFlight('user:1'))->toBe(1);

        return 'ok';
    }, scope: 'chat', owner: 'user:1');

    expect($result)->toBe('ok')
        ->and($limiter->inFlight('user:1'))->toBe(0);
});

it('run releases the slot even when the callback throws', function () {
    expect(fn () => $this->guard->run(fn () => throw new RuntimeException('boom'), owner: 'user:1'))
        ->toThrow(RuntimeException::class);

    expect(app(TurnConcurrencyLimiter::class)->inFlight('user:1'))->toBe(0);
});

it('run without an owner skips concurrency entirely', function () {
    $limiter = new TurnConcurrencyLimiter(app('cache')->store(), 0, 600);
    $guard = new TurnGuard(app(KillSwitch::class), app(BudgetGuard::class), $limiter);

    expect($guard->run(fn () => 42))->toBe(42)
        ->and(fn () => $guard->run(fn () => 42, owner: 'user:1'))
        ->toThrow(TooManyConcurrentTurnsException::class);
});

it('run refuses before taking a slot when killed', function () {
    app(KillSwitch::class)->engage();

    $ran = false;

    expect(fn () => $this->guard->run(function () use (&$ran) {
        $ran = true;
    }, owner: 'user:1'))->toThrow(AiKilledException::class);

    expect($ran)->toBeFalse()
        ->and(app(TurnConcurrencyLimiter::class)->inFlight('user:1'))->toBe(0);
});

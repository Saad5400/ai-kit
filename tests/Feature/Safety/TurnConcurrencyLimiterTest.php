<?php

use Saad\AiKit\Safety\Exceptions\TooManyConcurrentTurnsException;
use Saad\AiKit\Safety\TurnConcurrencyLimiter;

function makeLimiter(?int $max, int $ttl = 600): TurnConcurrencyLimiter
{
    return new TurnConcurrencyLimiter(app('cache')->store(), $max, $ttl);
}

it('admits up to the cap and rejects beyond it', function () {
    $limiter = makeLimiter(2);

    $limiter->acquire('user:1');
    $limiter->acquire('user:1');

    expect($limiter->inFlight('user:1'))->toBe(2)
        ->and(fn () => $limiter->acquire('user:1'))->toThrow(TooManyConcurrentTurnsException::class);

    expect($limiter->inFlight('user:1'))->toBe(2);
});

it('tracks owners independently', function () {
    $limiter = makeLimiter(1);

    $limiter->acquire('user:1');
    $limiter->acquire('user:2');

    expect($limiter->inFlight('user:1'))->toBe(1)
        ->and($limiter->inFlight('user:2'))->toBe(1);
});

it('release frees a slot', function () {
    $limiter = makeLimiter(1);

    $limiter->acquire('user:1');
    $limiter->release('user:1');
    $limiter->acquire('user:1');

    expect($limiter->inFlight('user:1'))->toBe(1);
});

it('release never goes negative', function () {
    $limiter = makeLimiter(2);

    $limiter->release('user:1');

    expect($limiter->inFlight('user:1'))->toBe(0);
});

it('run releases the slot even when the callback throws', function () {
    $limiter = makeLimiter(1);

    expect(fn () => $limiter->run('user:1', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect($limiter->inFlight('user:1'))->toBe(0);

    $result = $limiter->run('user:1', fn () => 'ok');

    expect($result)->toBe('ok');
});

it('a null cap disables the limiter', function () {
    $limiter = makeLimiter(null);

    foreach (range(1, 25) as $i) {
        $limiter->acquire('user:1');
    }

    expect($limiter->inFlight('user:1'))->toBe(0);
});

it('exception carries retry-after and a localized reason', function () {
    $limiter = makeLimiter(1);
    $limiter->acquire('user:1');

    try {
        $limiter->acquire('user:1');
        $this->fail('Expected TooManyConcurrentTurnsException');
    } catch (TooManyConcurrentTurnsException $e) {
        expect($e->retryAfterSeconds())->toBe(15)
            ->and($e->maxConcurrent)->toBe(1)
            ->and($e->userFacingReason())->toBe(__('ai-kit::safety.too_many_turns'));
    }
});

<?php

use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Saad\AiKit\Gateway\ModelCircuitBreaker;

function breaker(int $threshold = 3, int $cooldown = 60, int $halfOpen = 30): ModelCircuitBreaker
{
    return new ModelCircuitBreaker(
        cache()->store('array'),
        failureThreshold: $threshold,
        windowSeconds: 120,
        cooldownSeconds: $cooldown,
        halfOpenSeconds: $halfOpen,
    );
}

it('stays closed below the failure threshold', function () {
    $breaker = breaker(threshold: 3);

    $breaker->recordFailure('openrouter', 'm');
    $breaker->recordFailure('openrouter', 'm');

    $breaker->guard('openrouter', 'm');

    expect($breaker->isOpen('openrouter', 'm'))->toBeFalse();
});

it('opens at the threshold and guards without touching the network', function () {
    $breaker = breaker(threshold: 3);

    foreach (range(1, 3) as $i) {
        $breaker->recordFailure('openrouter', 'm');
    }

    expect($breaker->isOpen('openrouter', 'm'))->toBeTrue()
        ->and(fn () => $breaker->guard('openrouter', 'm'))
        ->toThrow(ProviderOverloadedException::class, 'Circuit breaker open');
});

it('tracks each provider and model pair independently', function () {
    $breaker = breaker(threshold: 1);

    $breaker->recordFailure('openrouter', 'bad/model');

    expect($breaker->isOpen('openrouter', 'bad/model'))->toBeTrue();

    $breaker->guard('openrouter', 'good/model');
});

it('clears the failure count on success', function () {
    $breaker = breaker(threshold: 2);

    $breaker->recordFailure('openrouter', 'm');
    $breaker->recordSuccess('openrouter', 'm');
    $breaker->recordFailure('openrouter', 'm');

    expect($breaker->isOpen('openrouter', 'm'))->toBeFalse();
});

it('allows exactly one probe after the cooldown', function () {
    $breaker = breaker(threshold: 1, cooldown: 60);

    $breaker->recordFailure('openrouter', 'm');

    $this->travel(61)->seconds();

    $breaker->guard('openrouter', 'm');

    expect(fn () => $breaker->guard('openrouter', 'm'))
        ->toThrow(ProviderOverloadedException::class);
});

it('closes fully when the probe succeeds', function () {
    $breaker = breaker(threshold: 1, cooldown: 60);

    $breaker->recordFailure('openrouter', 'm');

    $this->travel(61)->seconds();

    $breaker->guard('openrouter', 'm');
    $breaker->recordSuccess('openrouter', 'm');

    $breaker->guard('openrouter', 'm');
    $breaker->guard('openrouter', 'm');

    expect($breaker->isOpen('openrouter', 'm'))->toBeFalse();
});

it('reopens when the probe fails', function () {
    $breaker = breaker(threshold: 3, cooldown: 60);

    foreach (range(1, 3) as $i) {
        $breaker->recordFailure('openrouter', 'm');
    }

    $this->travel(61)->seconds();

    $breaker->guard('openrouter', 'm');
    $breaker->recordFailure('openrouter', 'm');

    expect($breaker->isOpen('openrouter', 'm'))->toBeTrue();
});

it('can be reset manually', function () {
    $breaker = breaker(threshold: 1);

    $breaker->recordFailure('openrouter', 'm');
    $breaker->reset('openrouter', 'm');

    expect($breaker->isOpen('openrouter', 'm'))->toBeFalse();

    $breaker->guard('openrouter', 'm');
});

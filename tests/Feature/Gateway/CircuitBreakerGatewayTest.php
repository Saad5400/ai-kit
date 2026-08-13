<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Gateway\ModelCircuitBreaker;
use Saad\AiKit\Gateway\ReasoningOpenRouterGateway;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;

function gatewayWithBreaker(int $threshold = 2): array
{
    $breaker = new ModelCircuitBreaker(cache()->store('array'), failureThreshold: $threshold);

    // attempts 1 = no client retries, so each step is one HTTP request.
    return [GatewayFactory::gateway(['retry' => ['attempts' => 1]], $breaker), $breaker];
}

function textStep(ReasoningOpenRouterGateway $gateway): mixed
{
    return $gateway->generateTextStep(
        GatewayFactory::provider(),
        'test/model',
        null,
        [new UserMessage('hi')],
        [],
        null,
        null,
        null,
        new StepContext,
    );
}

function streamStep(ReasoningOpenRouterGateway $gateway): Generator
{
    return $gateway->generateStreamStep(
        'inv-test',
        GatewayFactory::provider(),
        'test/model',
        null,
        [new UserMessage('hi')],
        [],
        null,
        null,
        null,
        new StepContext,
    );
}

it('opens after repeated 5xx step failures and blocks before any http call', function () {
    Http::fake(['*' => Http::sequence()
        ->pushStatus(500)
        ->pushStatus(500)
        ->push('must never be reached'),
    ]);

    [$gateway, $breaker] = gatewayWithBreaker(threshold: 2);

    foreach (range(1, 2) as $i) {
        try {
            textStep($gateway);
        } catch (ProviderOverloadedException) {
            // 500 maps to ProviderOverloadedException via overloadedStatusCodes.
        }
    }

    expect($breaker->isOpen('openrouter', 'test/model'))->toBeTrue()
        ->and(fn () => textStep($gateway))->toThrow(ProviderOverloadedException::class, 'Circuit breaker open');

    Http::assertSentCount(2);
});

it('records success and closes the circuit on a good step', function () {
    Http::fake(['*' => Http::sequence()
        ->pushStatus(500)
        ->push(OpenRouterSse::completion())
        ->pushStatus(500),
    ]);

    [$gateway, $breaker] = gatewayWithBreaker(threshold: 2);

    try {
        textStep($gateway);
    } catch (ProviderOverloadedException) {
    }

    textStep($gateway);

    try {
        textStep($gateway);
    } catch (ProviderOverloadedException) {
    }

    // The success in between reset the count, so one new failure stays closed.
    expect($breaker->isOpen('openrouter', 'test/model'))->toBeFalse();
});

it('widens overloaded statuses so 500s fail over after retries', function () {
    Http::fake(['*' => Http::response('down', 500)]);

    expect(fn () => textStep(GatewayFactory::gateway(['retry' => ['attempts' => 1]])))
        ->toThrow(ProviderOverloadedException::class);
});

it('respects a custom overloaded status list', function () {
    Http::fake(['*' => Http::response('down', 500)]);

    $gateway = GatewayFactory::gateway([
        'retry' => ['attempts' => 1],
        'failover' => ['overloaded_statuses' => [503]],
    ]);

    expect(fn () => textStep($gateway))
        ->toThrow(RequestException::class);
});

it('guards stream steps eagerly, before the generator is iterated', function () {
    [$gateway, $breaker] = gatewayWithBreaker(threshold: 1);

    $breaker->recordFailure('openrouter', 'test/model');

    Http::fake(['*' => Http::response('must never be reached', 200)]);

    expect(fn () => streamStep($gateway))->toThrow(ProviderOverloadedException::class);

    Http::assertNothingSent();
});

it('records a failed stream connection into the breaker', function () {
    Http::fake(['*' => Http::response('down', 500)]);

    [$gateway, $breaker] = gatewayWithBreaker(threshold: 1);

    try {
        iterator_to_array(streamStep($gateway));
    } catch (ProviderOverloadedException) {
    }

    expect($breaker->isOpen('openrouter', 'test/model'))->toBeTrue();
});

it('records a completed stream as a success', function () {
    Http::fake(['*' => Http::response(OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'Hello.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.001]),
    ]))]);

    [$gateway, $breaker] = gatewayWithBreaker(threshold: 1);

    $breaker->recordFailure('openrouter', 'other/model');

    iterator_to_array(streamStep($gateway));

    expect($breaker->isOpen('openrouter', 'test/model'))->toBeFalse();
});

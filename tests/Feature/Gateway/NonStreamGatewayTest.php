<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Catalog\ModelRouting;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;

function runTextStep(array $config = []): mixed
{
    return GatewayFactory::gateway($config)->generateTextStep(
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

it('captures generation id and cost into the non-streamed bucket', function () {
    Http::fake(['*' => Http::response(OpenRouterSse::completion(
        usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.005],
        id: 'gen-nonstream-1',
    ))]);

    $step = runTextStep();

    expect($step->text)->toBe('Hello.')
        ->and(Context::get('ai.openrouter_non_stream_generation_ids'))->toBe(['gen-nonstream-1'])
        ->and(Context::get('ai.openrouter_non_stream_costs'))->toBe([0.005])
        ->and(Context::get('ai.openrouter_costs'))->toBeNull();
});

it('puts the declared chain on the wire for OpenRouter to fail over', function () {
    config()->set('ai-kit.catalog.models', [
        'test/model' => ['fallbacks' => ['backup/one'], 'provider_max_price' => ['prompt' => 0.5]],
    ]);

    Http::fake(['*' => Http::response(OpenRouterSse::completion())]);

    GatewayFactory::gateway(routing: app(ModelRouting::class))->generateTextStep(
        GatewayFactory::provider(), 'test/model', null, [new UserMessage('hi')], [], null, null, null, new StepContext,
    );

    Http::assertSent(fn ($request) => $request->data()['models'] === ['test/model', 'backup/one']
        && $request->data()['provider'] === ['max_price' => ['prompt' => 0.5]]);
});

// OpenRouter returns full usage unasked now; the flag that forced
// `usage: {include: true}` is gone and the body carries no usage block.
it('no longer asks for usage accounting on the request body', function () {
    Http::fake(['*' => Http::response(OpenRouterSse::completion())]);

    runTextStep();

    Http::assertSent(fn ($request) => ! isset($request->data()['usage']));
});

it('turns an empty 200 body into a clean AiException, not a TypeError', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(fn () => runTextStep())
        ->toThrow(AiException::class, 'Empty or invalid OpenRouter response.');
});

it('retries transient statuses with backoff and succeeds', function () {
    Http::fake(['*' => Http::sequence()
        ->pushStatus(503)
        ->pushStatus(429)
        ->push(OpenRouterSse::completion(usage: ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.001])),
    ]);

    $step = runTextStep(['retry' => ['backoff_ms' => 1]]);

    expect($step->text)->toBe('Hello.');
    Http::assertSentCount(3);
});

it('does not retry non-transient statuses', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(400)->push(OpenRouterSse::completion())]);

    try {
        runTextStep(['retry' => ['backoff_ms' => 1]]);
        $this->fail('Expected an exception for HTTP 400');
    } catch (Throwable) {
        // Expected: 400 is not in the retryable set.
    }

    Http::assertSentCount(1);
});

it('honors a disabled retry policy', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(503)->push(OpenRouterSse::completion())]);

    try {
        runTextStep(['retry' => ['attempts' => 1]]);
        $this->fail('Expected an exception for HTTP 503');
    } catch (Throwable) {
        // Expected: single attempt, no retry.
    }

    Http::assertSentCount(1);
});

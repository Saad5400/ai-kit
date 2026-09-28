<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Catalog\ModelDefinition;
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

// laravel/ai 1.0 moved reasoning onto StepResponse; the gateway's inspected
// re-wrap must carry it or a non-streamed turn silently loses its thinking.
it('keeps the non-streamed reasoning through the inspected re-wrap', function () {
    $body = OpenRouterSse::completion();
    $body['choices'][0]['message']['reasoning'] = 'The user said hi.';

    Http::fake(['*' => Http::response($body)]);

    expect(runTextStep()->reasoning)->toBe('The user said hi.');
});

// 1.0 renamed prompt/completion to input/output and made them INCLUSIVE of
// cached and reasoning tokens. OpenRouter's prompt_tokens/completion_tokens
// always were inclusive, so for this gateway the numbers must not move: the
// usage row's prompt_tokens and the display estimate are unchanged from 0.10.
it('reports OpenRouter token counts unchanged: inclusive input/output, breakdown beside them', function () {
    Http::fake(['*' => Http::response(OpenRouterSse::completion(usage: [
        'prompt_tokens' => 1_000,
        'completion_tokens' => 300,
        'prompt_tokens_details' => ['cached_tokens' => 600],
        'completion_tokens_details' => ['reasoning_tokens' => 200],
        'cost' => 0.001,
    ]))]);

    $usage = runTextStep()->usage;

    $model = new ModelDefinition('test/model', inputUsdPerMillion: 1.0, outputUsdPerMillion: 10.0);

    expect($usage->inputTokens)->toBe(1_000)
        ->and($usage->outputTokens)->toBe(300)
        ->and($usage->cacheReadInputTokens)->toBe(600)
        ->and($usage->cacheWriteInputTokens)->toBeNull()
        ->and($usage->reasoningTokens)->toBe(200)
        ->and($usage->uncachedInputTokens())->toBe(400)
        // 1_000 × $1/M + 300 × $10/M — full counts, cached input at the base rate.
        ->and($model->displayCostEstimateUsd($usage))->toEqualWithDelta(0.004, 1e-12);
});

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Usage\TraceLogger;
use Saad\AiKit\Usage\UsageEvent;

uses(RefreshDatabase::class);

function traceRow(array $overrides = []): UsageEvent
{
    return UsageEvent::create(array_merge([
        'invocation_id' => '0198b000-0000-7000-8000-000000000001',
        'provider' => 'openrouter',
        'model' => 'test/model',
        'agent' => 'App\Ai\Agents\TestAgent',
        'streamed' => true,
        'prompt_tokens' => 100,
        'completion_tokens' => 25,
        'cost_usd' => 0.003,
        'duration_ms' => 1200,
        'ttft_ms' => 350,
        'status' => 'ok',
        'created_at' => now(),
    ], $overrides));
}

it('stamps ttft at the first streamed reasoning token', function () {
    TurnContext::stampStart('inv-test');

    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning' => 'hmm']),
        OpenRouterSse::chunk(['content' => 'Hello.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBeInt();
});

it('stamps ttft at the first text token when there is no reasoning', function () {
    TurnContext::stampStart('inv-test');

    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Hello.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBeInt();
});

it('never overwrites the first ttft on later steps', function () {
    TurnContext::stampStart('inv-test');
    Context::add(TurnContext::TTFT_KEY, 42);

    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Step two.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBe(42);
});

it('skips the ttft stamp entirely when no turn was started', function () {
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Hello.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBeNull();
});

it('emits a turn trace with otel genai attribute names', function () {
    $logger = Mockery::mock(LoggerInterface::class);

    $logger->shouldReceive('info')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'gen_ai.turn'
            && $context['gen_ai.system'] === 'openrouter'
            && $context['gen_ai.response.model'] === 'test/model'
            && $context['gen_ai.usage.input_tokens'] === 100
            && $context['gen_ai.usage.output_tokens'] === 25
            && $context['ai_kit.cost_usd'] === 0.003
            && $context['ai_kit.ttft_ms'] === 350
            && $context['ai_kit.status'] === 'ok'
    );

    Log::shouldReceive('channel')->with(null)->andReturn($logger);

    app(TraceLogger::class)->turn(traceRow());
});

it('routes traces to the configured channel', function () {
    config()->set('ai-kit.usage.trace.channel', 'stack');

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->once();

    Log::shouldReceive('channel')->with('stack')->andReturn($logger);

    app(TraceLogger::class)->turn(traceRow());
});

it('stays silent when tracing is disabled', function () {
    config()->set('ai-kit.usage.trace.enabled', false);

    Log::shouldReceive('channel')->never();

    app(TraceLogger::class)->turn(traceRow());
});

it('logs failover attempts as warnings', function () {
    $logger = Mockery::mock(LoggerInterface::class);

    $logger->shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'gen_ai.failover'
            && $context['gen_ai.response.model'] === 'test/model'
            && str_contains($context['ai_kit.error'], 'RateLimited')
    );

    Log::shouldReceive('channel')->with(null)->andReturn($logger);

    app(TraceLogger::class)->failover(traceRow([
        'status' => 'failed_over',
        'error' => 'Laravel\Ai\Exceptions\RateLimitedException: rate limited',
    ]));
});

<?php

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;

it('re-emits reasoning deltas around the text stream', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning' => 'thin']),
        OpenRouterSse::chunk(['reasoning' => 'king']),
        OpenRouterSse::chunk(['content' => 'Hel']),
        OpenRouterSse::chunk(['content' => 'lo'], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0123]),
    ], $events);

    expect(array_map(get_class(...), $events))->toBe([
        StreamStart::class,
        ReasoningStart::class,
        ReasoningDelta::class,
        ReasoningDelta::class,
        ReasoningEnd::class,
        TextStart::class,
        TextDelta::class,
        TextDelta::class,
        TextEnd::class,
    ]);

    expect($step)->toBeInstanceOf(StepResponse::class)
        ->and($step->text)->toBe('Hello')
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->usage->promptTokens)->toBe(10);
});

it('captures generation id and exact cost into the streamed bucket', function () {
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Hi'], finishReason: 'stop', id: 'gen-abc123'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.005], id: 'gen-abc123'),
    ]);

    expect(Context::get('ai.openrouter_generation_ids'))->toBe(['gen-abc123'])
        ->and(Context::get('ai.openrouter_costs'))->toBe([0.005])
        ->and(Context::get('ai.openrouter_non_stream_costs'))->toBeNull();
});

it('understands DeepSeek-style reasoning_content deltas', function () {
    $events = [];
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning_content' => 'hmm']),
        OpenRouterSse::chunk(['content' => 'ok'], finishReason: 'stop'),
    ], $events);

    expect(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))->toHaveCount(1);
});

it('closes a reasoning block that never gave way to content', function () {
    $events = [];
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning' => 'only thinking']),
    ], $events);

    expect(array_map(get_class(...), $events))->toBe([
        StreamStart::class,
        ReasoningStart::class,
        ReasoningDelta::class,
        ReasoningEnd::class,
    ]);
});

it('assembles streamed tool calls', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{"q":']]]]),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"x"}']]]], finishReason: 'tool_calls'),
    ], $events);

    $toolEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

    expect($toolEvents)->toHaveCount(1)
        ->and($step->toolCalls[0]->name)->toBe('lookup')
        ->and($step->toolCalls[0]->arguments)->toBe(['q' => 'x'])
        ->and($step->finishReason)->toBe(FinishReason::ToolCalls);
});

it('emits streamed url citations (regression: the app forks dropped these)', function () {
    $events = [];
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'See source.']),
        OpenRouterSse::chunk(['annotations' => [[
            'type' => 'url_citation',
            'url_citation' => ['url' => 'https://example.com', 'title' => 'Example', 'start_index' => 0, 'end_index' => 10],
        ]]], finishReason: 'stop'),
    ], $events);

    $citations = array_values(array_filter($events, fn ($e) => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(1)
        ->and($citations[0]->citation->url)->toBe('https://example.com');
});

it('surfaces top-level error frames and stops', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        ['error' => ['code' => 429, 'message' => 'rate limited']],
        OpenRouterSse::chunk(['content' => 'never emitted']),
    ], $events);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(Error::class)
        ->and($step)->toBeNull();
});

it('surfaces provider finish_reason errors', function () {
    $events = [];
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk([], finishReason: 'error', extra: ['error' => ['code' => 502, 'message' => 'upstream died']]),
    ], $events);

    $errors = array_values(array_filter($events, fn ($e) => $e instanceof Error));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->message)->toBe('upstream died');
});

it('keeps the last good cost when a later usage frame reports none', function () {
    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Hi'], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.01]),
        OpenRouterSse::usageFrame(['prompt_tokens' => 2, 'completion_tokens' => 2]),
    ]);

    expect(Context::get('ai.openrouter_costs'))->toBe([0.01]);
});

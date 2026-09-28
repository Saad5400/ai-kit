<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Saad\AiKit\Gateway\InspectedStepResponse;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;

function providerChunk(array $delta, ?string $finishReason = null, string $provider = 'DeepSeek'): array
{
    return OpenRouterSse::chunk($delta, $finishReason) + ['provider' => $provider];
}

it('never emits leaked tool-call markup as text and flags the step', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        providerChunk(['content' => 'لنبحث أولاً. ']),
        providerChunk(['content' => '<｜DSML｜tool_ca']),
        providerChunk(['content' => 'lls><｜DSML｜invoke name="ListRecords"></｜DSML｜invoke>']),
        providerChunk([], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 20, 'cost' => 0.001]),
    ], $events);

    $text = implode('', array_map(fn ($e) => $e->delta, array_filter($events, fn ($e) => $e instanceof TextDelta)));

    expect($text)->toBe('لنبحث أولاً. ')
        ->and($step)->toBeInstanceOf(InspectedStepResponse::class)
        ->and($step->text)->toBe('لنبحث أولاً. ')
        ->and($step->markupLeaked)->toBeTrue()
        ->and($step->leakedMarkup)->toContain('invoke name="ListRecords"')
        ->and($step->providerName)->toBe('DeepSeek')
        ->and($step->toolCalls)->toBe([]);
});

it('opens no text block at all when the step was nothing but markup', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        providerChunk(['content' => '<|DSML|tool_calls><|DSML|invoke name="x"></|DSML|invoke>'], finishReason: 'stop'),
    ], $events);

    expect(array_map(get_class(...), $events))->toBe([StreamStart::class])
        ->and($step->text)->toBe('')
        ->and($step->markupLeaked)->toBeTrue();
});

it('leaves a clean step byte-identical, including text with angle brackets', function () {
    $frames = [
        OpenRouterSse::chunk(['content' => 'Use <b>bold</b> ']),
        OpenRouterSse::chunk(['content' => 'and 3 <']),
        OpenRouterSse::chunk(['content' => ' 4.']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ];

    $guarded = [];
    $bare = [];
    GatewayFactory::streamed(GatewayFactory::gateway(), $frames, $guarded);
    GatewayFactory::streamed(GatewayFactory::gateway(['markup_leak' => ['enabled' => false]]), $frames, $bare);

    $text = fn (array $events) => implode('', array_map(fn ($e) => $e->delta, array_filter($events, fn ($e) => $e instanceof TextDelta)));

    expect(array_map(get_class(...), $guarded))->toBe([StreamStart::class, TextStart::class, TextDelta::class, TextDelta::class, TextDelta::class, TextEnd::class])
        ->and($text($guarded))->toBe('Use <b>bold</b> and 3 < 4.')
        ->and($text($guarded))->toBe($text($bare));
});

it('strips the leak on the non-streamed path as well', function () {
    Http::fake(['*' => Http::response(
        OpenRouterSse::completion('Checking. <｜DSML｜invoke name="x"></｜DSML｜invoke>') + ['provider' => 'DeepSeek'],
    )]);

    $step = GatewayFactory::gateway(['retry' => ['attempts' => 1], 'markup_leak' => ['retry' => false]])->generateTextStep(
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

    expect($step)->toBeInstanceOf(InspectedStepResponse::class)
        ->and($step->text)->toBe('Checking. ')
        ->and($step->markupLeaked)->toBeTrue()
        ->and($step->providerName)->toBe('DeepSeek');
});

it('releases a held tail as text ahead of TextEnd when no marker followed', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'x <']),
        OpenRouterSse::chunk([], finishReason: 'stop'),
    ], $events);

    $deltas = array_values(array_map(fn ($e) => $e->delta, array_filter($events, fn ($e) => $e instanceof TextDelta)));

    expect(array_map(get_class(...), $events))->toBe([StreamStart::class, TextStart::class, TextDelta::class, TextDelta::class, TextEnd::class])
        ->and($deltas)->toBe(['x ', '<'])
        ->and($step->text)->toBe('x <')
        ->and($step->markupLeaked)->toBeFalse();
});

it('opens the text block for a tail that was the only content, after closing reasoning', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning' => 'hmm']),
        OpenRouterSse::chunk(['content' => '<'], finishReason: 'stop'),
    ], $events);

    expect(array_map(get_class(...), $events))->toBe([
        StreamStart::class,
        ReasoningStart::class,
        ReasoningDelta::class,
        ReasoningEnd::class,
        TextStart::class,
        TextDelta::class,
        TextEnd::class,
    ])->and($step->text)->toBe('<');
});

it('emits the released tail before the step tool calls', function () {
    $events = [];
    $step = GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['content' => 'Calling <']),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]], finishReason: 'tool_calls'),
    ], $events);

    expect(array_map(get_class(...), $events))->toBe([
        StreamStart::class,
        TextStart::class,
        TextDelta::class,
        TextDelta::class,
        TextEnd::class,
        ToolCallEvent::class,
    ])->and($step->text)->toBe('Calling <')
        ->and($step->toolCalls)->toHaveCount(1);
});

it('never stamps ttft for a step that was nothing but markup', function () {
    TurnContext::stampStart('inv-test');

    GatewayFactory::streamed(GatewayFactory::gateway(), [
        providerChunk(['content' => '<|DSML|tool_calls><|DSML|invoke name="x"></|DSML|invoke>'], finishReason: 'stop'),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBeNull();
});

it('stamps ttft at a DeepSeek reasoning_content token', function () {
    TurnContext::stampStart('inv-test');

    GatewayFactory::streamed(GatewayFactory::gateway(), [
        OpenRouterSse::chunk(['reasoning_content' => 'thinking']),
    ]);

    expect(Context::get(TurnContext::TTFT_KEY))->toBeInt();
});

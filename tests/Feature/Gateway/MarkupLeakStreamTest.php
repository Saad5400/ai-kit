<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Saad\AiKit\Gateway\InspectedStepResponse;
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

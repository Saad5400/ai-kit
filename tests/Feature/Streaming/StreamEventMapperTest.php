<?php

use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\TextTransformer;

function fakeDelta(string $text): TextDelta
{
    return new TextDelta(uniqid('e'), 'm1', $text, 1);
}

beforeEach(function () {
    $this->mapper = $this->app->make(StreamEventMapper::class);
    $this->events = [];
    $this->emit = function (string $event, array $data): void {
        $this->events[] = [$event, $data];
    };
});

it('folds text deltas into delta events and a terminal done', function () {
    $this->mapper->doneUsing(fn ($result) => [
        'text' => $result->text,
        'completion_tokens' => $result->usage?->completionTokens,
    ]);

    $result = $this->mapper->run([
        fakeDelta('Hel'),
        fakeDelta('lo'),
        new StreamEnd('s1', 'stop', new Usage(completionTokens: 5), 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'Hel']],
        ['delta', ['text' => 'lo']],
        ['done', ['text' => 'Hello', 'completion_tokens' => 5]],
    ])->and($result->failed)->toBeFalse()
        ->and($result->text)->toBe('Hello');
});

it('sums usage across multi-step stream ends', function () {
    $result = $this->mapper->run([
        new StreamEnd('s1', 'tool_use', new Usage(promptTokens: 10, completionTokens: 2), 1),
        new StreamEnd('s2', 'stop', new Usage(promptTokens: 15, completionTokens: 3), 2),
    ], $this->emit);

    expect($result->usage->promptTokens)->toBe(25)
        ->and($result->usage->completionTokens)->toBe(5);
});

it('emits error and stops without a done on a stream error', function () {
    $this->mapper->onError(fn (Error $event) => 'حدث خطأ أثناء توليد الرد.');

    $result = $this->mapper->run([
        fakeDelta('partial'),
        new Error('e1', 'provider_error', 'upstream exploded', false, 1),
        fakeDelta('never'),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'partial']],
        ['error', ['message' => 'حدث خطأ أثناء توليد الرد.']],
    ])->and($result->failed)->toBeTrue()
        ->and($result->error->message)->toBe('upstream exploded');
});

it('defaults the error message to the provider message', function () {
    $this->mapper->run([new Error('e1', 'provider_error', 'boom', false, 1)], $this->emit);

    expect($this->events)->toBe([['error', ['message' => 'boom']]]);
});

it('pipes text through closure transformers per delta', function () {
    $this->mapper->transformText(fn (string $text) => strtoupper($text));

    $this->mapper->run([fakeDelta('a'), fakeDelta('b')], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'A']],
        ['delta', ['text' => 'B']],
        ['done', []],
    ]);
});

it('flushes text a stateful transformer held back, through the rest of the pipeline', function () {
    // Holds everything until the stream ends — the shape of uqucc's
    // streaming link guard, taken to the extreme.
    $holdAll = new class implements TextTransformer
    {
        private string $held = '';

        public function push(string $delta): string
        {
            $this->held .= $delta;

            return '';
        }

        public function flush(): string
        {
            return $this->held;
        }
    };

    $this->mapper
        ->transformText($holdAll)
        ->transformText(fn (string $text) => str_replace('b', 'B', $text));

    $result = $this->mapper->run([fakeDelta('a'), fakeDelta('b')], $this->emit);

    // Nothing mid-stream; the held tail is released through the downstream
    // stage right before done.
    expect($this->events)->toBe([
        ['delta', ['text' => 'aB']],
        ['done', []],
    ])->and($result->text)->toBe('aB');
});

it('suppresses empty transformed deltas', function () {
    $this->mapper->transformText(fn () => '');

    $this->mapper->run([fakeDelta('a')], $this->emit);

    expect($this->events)->toBe([['done', []]]);
});

it('drops reasoning deltas unless a handler opts in', function () {
    $this->mapper->run([new ReasoningDelta('r1', 'rid', 'hmm', 1)], $this->emit);

    expect($this->events)->toBe([['done', []]]);
});

it('routes reasoning deltas to the registered handler', function () {
    $this->mapper->onReasoning(fn (ReasoningDelta $event, callable $emit) => $emit('reasoning', ['text' => $event->delta]));

    $this->mapper->run([new ReasoningDelta('r1', 'rid', 'hmm', 1)], $this->emit);

    expect($this->events)->toBe([
        ['reasoning', ['text' => 'hmm']],
        ['done', []],
    ]);
});

it('lets a hook replace an event with an extension event while bookkeeping still collects', function () {
    $this->mapper->on(ToolCall::class, fn (ToolCall $event, callable $emit) => $emit('step', ['tool' => $event->toolCall->name]));

    $result = $this->mapper->run([
        new ToolCall('tc1', new ToolCallData('id1', 'search', ['q' => 'x']), 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['step', ['tool' => 'search']],
        ['done', []],
    ])->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolCalls[0]->name)->toBe('search');
});

it('lets a hook intercept text deltas, replacing the default delta emission', function () {
    $this->mapper->on(TextDelta::class, fn (TextDelta $event, callable $emit) => $emit('segment', ['text' => $event->delta]));

    $result = $this->mapper->run([fakeDelta('raw')], $this->emit);

    expect($this->events)->toBe([
        ['segment', ['text' => 'raw']],
        ['done', []],
    ])->and($result->text)->toBe('');
});

it('runs beforeDone callbacks between the stream draining and done', function () {
    $this->mapper->beforeDone(function ($result, callable $emit) {
        if ($result->toolResults !== []) {
            $emit('citations', ['items' => [['title' => 'page']]]);
        }
    });

    $result = $this->mapper->run([
        new ToolResult('tr1', new ToolResultData('id1', 'search', [], 'found'), true, null, 1),
        fakeDelta('answer'),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'answer']],
        ['citations', ['items' => [['title' => 'page']]]],
        ['done', []],
    ])->and($result->toolResults)->toHaveCount(1);
});

it('lets an error hook continue past a recoverable failure by returning true', function () {
    $this->mapper->on(Error::class, function (Error $event, callable $emit) {
        if ($event->recoverable) {
            return true;
        }

        $emit('error', ['message' => 'fatal']);
    });

    $result = $this->mapper->run([
        new Error('e1', 'overloaded', 'retrying', true, 1),
        fakeDelta('recovered'),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'recovered']],
        ['done', []],
    ])->and($result->failed)->toBeFalse()
        ->and($result->error->type)->toBe('overloaded');
});

it('stops on a hooked non-recoverable error', function () {
    $this->mapper->on(Error::class, fn (Error $event, callable $emit) => $emit('error', ['message' => 'fatal']));

    $result = $this->mapper->run([
        new Error('e1', 'provider_error', 'boom', false, 1),
        fakeDelta('never'),
    ], $this->emit);

    expect($this->events)->toBe([['error', ['message' => 'fatal']]])
        ->and($result->failed)->toBeTrue();
});

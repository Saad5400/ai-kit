<?php

use Illuminate\Support\Carbon;
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
use Saad\AiKit\Streaming\TurnBuffer;

function coalesceDelta(string $text): TextDelta
{
    return new TextDelta(uniqid('e'), 'm1', $text, 1);
}

beforeEach(function () {
    // Freeze the clock so runs never flush on a slow-CI window; the tests
    // that exercise the window advance it explicitly.
    Carbon::setTestNow(Carbon::now());

    $this->mapper = $this->app->make(StreamEventMapper::class);
    $this->events = [];
    $this->emit = function (string $event, array $data): void {
        $this->events[] = [$event, $data];
    };
});

it('keeps inline run() uncoalesced by default', function () {
    $this->mapper->run([coalesceDelta('Hel'), coalesceDelta('lo')], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'Hel']],
        ['delta', ['text' => 'lo']],
        ['done', []],
    ]);
});

it('merges consecutive deltas into one frame when coalescing, with identical result text', function () {
    $result = $this->mapper->coalesce()->run([
        coalesceDelta('Hel'),
        coalesceDelta('lo'),
        new StreamEnd('s1', 'stop', new Usage(completionTokens: 5), 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'Hello']],
        ['done', []],
    ])->and($result->text)->toBe('Hello');
});

it('flushes a held run when the window elapses', function () {
    $stream = function (): Generator {
        yield coalesceDelta('a');

        Carbon::setTestNow(Carbon::now()->addMilliseconds(150));

        yield coalesceDelta('b');
    };

    $this->mapper->coalesce(windowMs: 100)->run($stream(), $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'a']],
        ['delta', ['text' => 'b']],
        ['done', []],
    ]);
});

it('flushes a held run when it reaches maxChars', function () {
    $this->mapper->coalesce(maxChars: 4)->run([
        coalesceDelta('ab'),
        coalesceDelta('cd'),
        coalesceDelta('ef'),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'abcd']],
        ['delta', ['text' => 'ef']],
        ['done', []],
    ]);
});

it('never merges delta and reasoning runs into each other', function () {
    $this->mapper->coalesce()->run([
        new ReasoningDelta('r1', 'rid', 'let me ', 1),
        new ReasoningDelta('r2', 'rid', 'think', 2),
        coalesceDelta('Hel'),
        coalesceDelta('lo'),
    ], $this->emit);

    expect($this->events)->toBe([
        ['reasoning', ['text' => 'let me think']],
        ['delta', ['text' => 'Hello']],
        ['done', []],
    ]);
});

it('flushes held text before a tool event so wire order is unchanged', function () {
    $this->mapper->coalesce()->run([
        coalesceDelta('Hel'),
        coalesceDelta('lo'),
        new ToolCall('tc1', new ToolCallData('id1', 'search', []), 1),
        coalesceDelta(' wor'),
        coalesceDelta('ld'),
        new ToolResult('tr1', new ToolResultData('id1', 'search', [], 'found'), true, null, 2),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'Hello']],
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'running']],
        ['delta', ['text' => ' world']],
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'done', 'successful' => true]],
        ['done', []],
    ]);
});

it('flushes held text before a hook-emitted event', function () {
    $this->mapper
        ->coalesce()
        ->on(ToolCall::class, fn (ToolCall $event, callable $emit) => $emit('approval', ['id' => $event->toolCall->id]));

    $this->mapper->run([
        coalesceDelta('a'),
        coalesceDelta('b'),
        new ToolCall('tc1', new ToolCallData('id1', 'rename', []), 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'ab']],
        ['approval', ['id' => 'id1']],
        ['done', []],
    ]);
});

it('flushes held text before a terminal error', function () {
    $this->mapper->coalesce()->onError(fn (Error $event) => 'generic');

    $result = $this->mapper->run([
        coalesceDelta('par'),
        coalesceDelta('tial'),
        new Error('e1', 'provider_error', 'boom', false, 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'partial']],
        ['error', ['message' => 'generic']],
    ])->and($result->failed)->toBeTrue()
        ->and($result->text)->toBe('partial');
});

it('flushes held text when the stream throws mid-fold', function () {
    $stream = function (): Generator {
        yield coalesceDelta('half ');
        yield coalesceDelta('done');

        throw new RuntimeException('worker died');
    };

    $mapper = $this->mapper->coalesce();
    $events = &$this->events;

    expect(fn () => $mapper->run($stream(), $this->emit))->toThrow(RuntimeException::class);

    // Uncoalesced, those frames had already been emitted before the throw;
    // parity demands they reach the sink here too.
    expect($events)->toBe([
        ['delta', ['text' => 'half done']],
    ]);
});

it('coalesces by default on runIntoBuffer', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->doneUsing(fn ($result) => ['text' => $result->text]);

    $this->mapper->runIntoBuffer([
        coalesceDelta('Hel'),
        coalesceDelta('lo'),
        new StreamEnd('s1', 'stop', new Usage(completionTokens: 5), 1),
    ], $buffer, 't1');

    expect($buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'Hello']],
        ['seq' => 2, 'event' => 'done', 'data' => ['text' => 'Hello']],
    ]);
});

it('withoutCoalescing keeps the buffered path frame-exact', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->withoutCoalescing()->runIntoBuffer([
        coalesceDelta('Hel'),
        coalesceDelta('lo'),
    ], $buffer, 't1');

    expect($buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'Hel']],
        ['seq' => 2, 'event' => 'delta', 'data' => ['text' => 'lo']],
        ['seq' => 3, 'event' => 'done', 'data' => []],
    ]);
});

it('produces identical concatenated text with and without coalescing', function () {
    $stream = fn (): array => [
        coalesceDelta('one '),
        coalesceDelta('two '),
        new ToolCall('tc1', new ToolCallData('id1', 'search', []), 1),
        coalesceDelta('three'),
    ];

    $textOf = function (StreamEventMapper $mapper) use ($stream): array {
        $frames = [];

        $result = $mapper->run($stream(), function (string $event, array $data) use (&$frames): void {
            if ($event === 'delta') {
                $frames[] = $data['text'];
            }
        });

        return [implode('', $frames), $result->text];
    };

    [$coalescedWire, $coalescedResult] = $textOf($this->app->make(StreamEventMapper::class)->coalesce());
    [$plainWire, $plainResult] = $textOf($this->app->make(StreamEventMapper::class));

    expect($coalescedWire)->toBe($plainWire)
        ->and($coalescedResult)->toBe($plainResult)
        ->and($coalescedResult)->toBe('one two three');
});

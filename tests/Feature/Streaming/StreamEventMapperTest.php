<?php

use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\StreamResult;
use Saad\AiKit\Streaming\TextTransformer;
use Saad\AiKit\Streaming\TurnBuffer;

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
        'completion_tokens' => $result->usage?->outputTokens,
    ]);

    $result = $this->mapper->run([
        fakeDelta('Hel'),
        fakeDelta('lo'),
        new StreamEnd('s1', 'stop', new TextUsage(outputTokens: 5), 1),
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
        new StreamEnd('s1', 'tool_use', new TextUsage(inputTokens: 10, outputTokens: 2), 1),
        new StreamEnd('s2', 'stop', new TextUsage(inputTokens: 15, outputTokens: 3), 2),
    ], $this->emit);

    expect($result->usage->inputTokens)->toBe(25)
        ->and($result->usage->outputTokens)->toBe(5);
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
        ['error', ['message' => 'حدث خطأ أثناء توليد الرد.', 'code' => 'stream_error']],
    ])->and($result->failed)->toBeTrue()
        ->and($result->error->message)->toBe('upstream exploded');
});

it('defaults the error message to the provider message', function () {
    $this->mapper->run([new Error('e1', 'provider_error', 'boom', false, 1)], $this->emit);

    expect($this->events)->toBe([['error', ['message' => 'boom', 'code' => 'stream_error']]]);
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

it('emits reasoning deltas by default', function () {
    $this->mapper->run([
        new ReasoningDelta('r1', 'rid', 'let me ', 1),
        new ReasoningDelta('r2', 'rid', 'think', 2),
    ], $this->emit);

    expect($this->events)->toBe([
        ['reasoning', ['text' => 'let me ']],
        ['reasoning', ['text' => 'think']],
        ['done', []],
    ]);
});

it('drops reasoning deltas when the app opts out', function () {
    $this->mapper->withoutReasoning();

    $this->mapper->run([new ReasoningDelta('r1', 'rid', 'hmm', 1), fakeDelta('answer')], $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'answer']],
        ['done', []],
    ]);
});

it('routes reasoning deltas to the registered handler instead of the default', function () {
    $this->mapper->onReasoning(fn (ReasoningDelta $event, callable $emit) => $emit('thinking', ['chunk' => $event->delta]));

    $this->mapper->run([new ReasoningDelta('r1', 'rid', 'hmm', 1)], $this->emit);

    expect($this->events)->toBe([
        ['thinking', ['chunk' => 'hmm']],
        ['done', []],
    ]);
});

it('emits running and done tool events without arguments or results', function () {
    $result = $this->mapper->run([
        new ToolCall('tc1', new ToolCallData('id1', 'search', ['q' => 'secret query']), 1),
        new ToolResult('tr1', new ToolResultData('id1', 'search', ['q' => 'secret query'], 'sensitive rows'), true, null, 2),
    ], $this->emit);

    expect($this->events)->toBe([
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'running']],
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'done', 'successful' => true]],
        ['done', []],
    ])->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolResults)->toHaveCount(1);

    // The payloads stay server-side; only the correlation id, the name and
    // the status ever reach a public-facing client.
    expect(json_encode($this->events))->not->toContain('secret query')
        ->and(json_encode($this->events))->not->toContain('sensitive rows');
});

it('skips a sub-agent preliminary results: one done chip, one collected result, no hook call', function () {
    $hooked = 0;

    $result = $this->mapper
        ->on(ToolResult::class, function (ToolResult $event, callable $emit) use (&$hooked): void {
            $hooked++;
            $emit('tool', ['id' => $event->toolResult->id, 'status' => 'done']);
        })
        ->run([
            new ToolCall('tc1', new ToolCallData('id1', 'researcher', []), 1),
            new ToolResult('p1', new ToolResultData('id1', 'researcher', [], 'so far'), true, null, 2, preliminary: true),
            new ToolResult('p2', new ToolResultData('id1', 'researcher', [], 'so far, more'), true, null, 3, preliminary: true),
            new ToolResult('tr1', new ToolResultData('id1', 'researcher', [], 'final'), true, null, 4),
        ], $this->emit);

    expect($this->events)->toBe([
        ['tool', ['id' => 'id1', 'name' => 'researcher', 'status' => 'running']],
        ['tool', ['id' => 'id1', 'status' => 'done']],
        ['done', []],
    ])->and($hooked)->toBe(1)
        ->and($result->toolResults)->toHaveCount(1)
        ->and($result->toolResults[0]->result)->toBe('final');
});

it('reports a failed tool on the wire', function () {
    $this->mapper->run([
        new ToolResult('tr1', new ToolResultData('id1', 'rename', [], null), false, 'exploded', 1),
    ], $this->emit);

    expect($this->events)->toBe([
        ['tool', ['id' => 'id1', 'name' => 'rename', 'status' => 'done', 'successful' => false]],
        ['done', []],
    ]);
});

it('drops tool events when the app opts out', function () {
    $this->mapper->withoutToolEvents();

    $result = $this->mapper->run([
        new ToolCall('tc1', new ToolCallData('id1', 'search', []), 1),
        new ToolResult('tr1', new ToolResultData('id1', 'search', [], 'found'), true, null, 2),
    ], $this->emit);

    expect($this->events)->toBe([['done', []]])
        // Opting out of the wire events does not opt out of bookkeeping.
        ->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolResults)->toHaveCount(1);
});

it('emits reasoning and tool events where they occur, ahead of text a transformer is holding', function () {
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

    $this->mapper->transformText($holdAll);

    $this->mapper->run([
        new ReasoningDelta('r1', 'rid', 'hmm', 1),
        fakeDelta('held '),
        new ToolCall('tc1', new ToolCallData('id1', 'search', []), 2),
        fakeDelta('too'),
        new ToolResult('tr1', new ToolResultData('id1', 'search', [], 'found'), true, null, 3),
    ], $this->emit);

    // The chip appears when the tool runs; the guarded prose lands when the
    // guard releases it, which is after.
    expect($this->events)->toBe([
        ['reasoning', ['text' => 'hmm']],
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'running']],
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'done', 'successful' => true]],
        ['delta', ['text' => 'held too']],
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
        ['tool', ['id' => 'id1', 'name' => 'search', 'status' => 'done', 'successful' => true]],
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

it('folds a stream into a buffered turn, with the buffer writing the one done', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1', ['user_id' => 7]);

    $this->mapper->doneUsing(fn ($result) => ['text' => $result->text]);

    $result = $this->mapper->runIntoBuffer([
        fakeDelta('Hel'),
        fakeDelta('lo'),
        new StreamEnd('s1', 'stop', new TextUsage(outputTokens: 5), 1),
    ], $buffer, 't1', ['conversation_id' => 'c9']);

    $turn = $buffer->get('t1');

    // Coalescing is the buffered path's default (v0.8.0): the two adjacent
    // deltas land as ONE log entry.
    expect($turn['status'])->toBe('done')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'Hello']],
            ['seq' => 2, 'event' => 'done', 'data' => ['text' => 'Hello']],
        ])
        ->and($turn['meta'])->toBe(['user_id' => 7, 'conversation_id' => 'c9'])
        ->and($result->text)->toBe('Hello');
});

it('ends a buffered turn on error with no done after it', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->onError(fn (Error $event) => 'حدث خطأ أثناء توليد الرد.');

    $result = $this->mapper->runIntoBuffer([
        fakeDelta('partial'),
        new Error('e1', 'provider_error', 'upstream exploded', false, 1),
        fakeDelta('never'),
    ], $buffer, 't1', ['conversation_id' => 'c9']);

    $turn = $buffer->get('t1');

    expect($turn['status'])->toBe('failed')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'partial']],
            ['seq' => 2, 'event' => 'error', 'data' => ['message' => 'حدث خطأ أثناء توليد الرد.', 'code' => 'stream_error']],
        ])
        ->and($turn['meta'])->toBe(['conversation_id' => 'c9', 'error' => 'حدث خطأ أثناء توليد الرد.', 'error_code' => 'stream_error'])
        ->and($result->failed)->toBeTrue();
});

it('resolves closure meta after the fold, so post-stream facts reach the terminal frame', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1', ['user_id' => 7]);

    $this->mapper->runIntoBuffer([
        fakeDelta('Hello'),
        new StreamEnd('s1', 'stop', new TextUsage(outputTokens: 5), 1),
    ], $buffer, 't1', fn (StreamResult $result): array => [
        'completion_tokens' => $result->usage?->outputTokens,
        'message' => ['role' => 'assistant', 'content' => $result->text],
    ]);

    expect($buffer->get('t1')['meta'])->toBe([
        'user_id' => 7,
        'completion_tokens' => 5,
        'message' => ['role' => 'assistant', 'content' => 'Hello'],
    ]);
});

it('resolves closure meta on the failed path too', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->runIntoBuffer([
        fakeDelta('partial'),
        new Error('e1', 'provider_error', 'boom', false, 1),
    ], $buffer, 't1', fn (StreamResult $result): array => ['partial' => $result->failed ? $result->text : null]);

    expect($buffer->get('t1')['meta'])->toBe(['partial' => 'partial', 'error' => 'boom', 'error_code' => 'stream_error']);
});

it('appends reasoning and tool events to a buffered turn without extra wiring', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->runIntoBuffer([
        new ReasoningDelta('r1', 'rid', 'hmm', 1),
        new ToolCall('tc1', new ToolCallData('id1', 'search', []), 2),
        new ToolResult('tr1', new ToolResultData('id1', 'search', [], 'found'), true, null, 3),
        fakeDelta('answer'),
    ], $buffer, 't1');

    expect($buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'reasoning', 'data' => ['text' => 'hmm']],
        ['seq' => 2, 'event' => 'tool', 'data' => ['id' => 'id1', 'name' => 'search', 'status' => 'running']],
        ['seq' => 3, 'event' => 'tool', 'data' => ['id' => 'id1', 'name' => 'search', 'status' => 'done', 'successful' => true]],
        ['seq' => 4, 'event' => 'delta', 'data' => ['text' => 'answer']],
        ['seq' => 5, 'event' => 'done', 'data' => []],
    ]);
});

it('stops on a cancelling generator composed in front of it, finishing on done', function () {
    // The documented cancellation pattern: the mapper has no hook, the
    // generator in front of it stops the provider stream itself.
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $untilCancelled = function (iterable $stream) use ($buffer): Generator {
        foreach ($stream as $event) {
            if ($buffer->isCancelled('t1')) {
                return;
            }

            yield $event;
        }
    };

    $stream = (function () use ($buffer): Generator {
        yield fakeDelta('before');

        $buffer->cancel('t1');

        yield fakeDelta('after');
    })();

    $this->mapper->doneUsing(fn ($result) => ['text' => $result->text]);

    $result = $this->mapper->runIntoBuffer($untilCancelled($stream), $buffer, 't1');

    expect($buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'before']],
        ['seq' => 2, 'event' => 'done', 'data' => ['text' => 'before']],
    ])
        ->and($result->failed)->toBeFalse();
});

it('emits identical event sequences inline and buffered', function (array $stream) {
    $inline = [];
    $this->mapper->doneUsing(fn ($result) => ['text' => $result->text]);
    $this->mapper->run($stream, function (string $event, array $data) use (&$inline): void {
        $inline[] = [$event, $data];
    });

    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $buffered = $this->app->make(StreamEventMapper::class);
    $buffered->doneUsing(fn ($result) => ['text' => $result->text]);
    $buffered->runIntoBuffer($stream, $buffer, 't1');

    $replayed = array_map(
        fn (array $event): array => [$event['event'], $event['data']],
        $buffer->get('t1')['events'],
    );

    expect($replayed)->toBe($inline);
})->with([
    'a turn that completes' => [fn () => [fakeDelta('hi'), new StreamEnd('s1', 'stop', new TextUsage(outputTokens: 2), 1)]],
    'a turn that fails' => [fn () => [fakeDelta('partial'), new Error('e1', 'provider_error', 'boom', false, 1)]],
]);

it('pulls the stream once more after an error so laravel/ai can throw through its own failure path', function () {
    $error = new Error('e1', 'provider_error', 'boom', false, 1);
    $vendorSawThrow = false;

    $result = $this->mapper->run((function () use ($error, &$vendorSawThrow): Generator {
        yield fakeDelta('partial');
        yield $error;

        // 1.0's loop: the step ended without a response.
        $vendorSawThrow = true;

        throw new StreamErrorException($error);
    })(), $this->emit);

    expect($vendorSawThrow)->toBeTrue()
        ->and($this->events)->toBe([
            ['delta', ['text' => 'partial']],
            ['error', ['message' => 'boom', 'code' => 'stream_error']],
        ])
        ->and($result->failed)->toBeTrue()
        ->and($result->text)->toBe('partial');
});

it('emits the terminal error BEFORE it pulls the stream again', function () {
    $error = new Error('e1', '502', 'boom', false, 1);
    $seenAtPull = null;

    $this->mapper->run((function () use ($error, &$seenAtPull): Generator {
        yield $error;

        $seenAtPull = array_column($this->events, 0);

        throw new StreamErrorException($error);
    })(), $this->emit);

    expect($seenAtPull)->toBe(['error']);
});

it('never follows a stream that keeps yielding past its error', function () {
    $pulls = 0;

    $result = $this->mapper->run((function () use (&$pulls): Generator {
        yield new Error('e1', 'provider_error', 'boom', false, 1);
        $pulls++;
        yield fakeDelta('a new step');
        $pulls++;
        yield fakeDelta('and another');
    })(), $this->emit);

    expect($pulls)->toBe(1)
        ->and(array_column($this->events, 0))->toBe(['error'])
        ->and($result->text)->toBe('');
});

it('ends a recoverable-hooked error failed when 1.0 throws on it, with one default error frame', function () {
    $this->mapper->on(Error::class, fn (): bool => true);
    $error = new Error('e1', '429', 'slow down', true, 1);

    $result = $this->mapper->run((function () use ($error): Generator {
        yield fakeDelta('half');
        yield $error;

        throw new StreamErrorException($error);
    })(), $this->emit);

    expect($this->events)->toBe([
        ['delta', ['text' => 'half']],
        ['error', ['message' => 'slow down', 'code' => 'rate_limited']],
    ])->and($result->failed)->toBeTrue()
        ->and($result->text)->toBe('half');
});

it('settles a hooked terminal error without a second frame when 1.0 throws on it', function () {
    $this->mapper->on(Error::class, fn (Error $event, callable $emit) => $emit('error', ['message' => 'fatal']));
    $error = new Error('e1', 'provider_error', 'boom', false, 1);

    $result = $this->mapper->run((function () use ($error): Generator {
        yield $error;

        throw new StreamErrorException($error);
    })(), $this->emit);

    expect($this->events)->toBe([['error', ['message' => 'fatal']]])
        ->and($result->failed)->toBeTrue();
});

it('rethrows a StreamErrorException that no error event announced', function () {
    expect(fn () => $this->mapper->run((function (): Generator {
        yield fakeDelta('x');

        throw new StreamErrorException;
    })(), $this->emit))->toThrow(StreamErrorException::class);
});

it('collects into a caller-supplied result that survives a throw', function () {
    $result = new StreamResult;

    try {
        $this->mapper->run((function (): Generator {
            yield fakeDelta('kept');
            yield new ToolCall('tc1', new ToolCallData('call_1', 'search', [], 'call_1'), 1);

            throw new RuntimeException('down');
        })(), $this->emit, $result);
    } catch (RuntimeException) {
        //
    }

    expect($result->text)->toBe('kept')
        ->and($result->toolCalls)->toHaveCount(1);
});

it('carries the error code through runIntoBuffer to the buffered terminal', function () {
    $buffer = $this->app->make(TurnBuffer::class);
    $buffer->start('t1');

    $this->mapper->runIntoBuffer([new Error('e1', '503', 'down', false, 1)], $buffer, 't1');

    expect($buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'error', 'data' => ['message' => 'down', 'code' => 'provider_unavailable']],
    ])->and($buffer->get('t1')['meta']['error_code'])->toBe('provider_unavailable');
});

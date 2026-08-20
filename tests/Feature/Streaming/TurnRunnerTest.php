<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Streaming\TurnRunner;
use Saad\AiKit\Tests\Support\LongTurnBuffer;

function runnerDelta(string $text): TextDelta
{
    return new TextDelta(uniqid('e'), 'm1', $text, 1);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::now());

    $this->runner = $this->app->make(TurnRunner::class);
    $this->mapper = $this->app->make(StreamEventMapper::class);
    $this->buffer = new LongTurnBuffer;
    $this->buffer->start('t1', ['user_id' => 7]);
});

afterEach(function () {
    ToolProgress::unbind();
});

it('folds the stream into the buffer and hands the terminal back on the outcome', function () {
    $this->mapper->doneUsing(fn ($result) => ['text' => $result->text]);

    $outcome = $this->runner->run(
        turnId: 't1',
        stream: fn (): array => [
            runnerDelta('Hel'),
            runnerDelta('lo'),
            new StreamEnd('s1', 'stop', new Usage(completionTokens: 5), 1),
        ],
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    expect($outcome->failed)->toBeFalse()
        ->and($outcome->cancelled)->toBeFalse()
        ->and($outcome->done)->toBe(['text' => 'Hello'])
        ->and($outcome->result->text)->toBe('Hello');

    // Coalescing is ON by default here, the terminal is HELD BACK (the app
    // writes its own finish/fail), and the record is still running.
    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('running')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'Hello']],
        ]);

    // The cancel generator polled at least once, touching the heartbeat.
    expect($this->buffer->touches)->toBeGreaterThanOrEqual(1);
});

it('fails a kill-switched turn without ever opening the stream', function () {
    app(KillSwitch::class)->engage('assistant');

    $opened = false;

    $outcome = $this->runner->run(
        turnId: 't1',
        stream: function () use (&$opened): array {
            $opened = true;

            return [runnerDelta('never')];
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
        feature: 'assistant',
    );

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->failure)->toBe(__('ai-kit::safety.killed'))
        ->and($outcome->exception)->toBeNull()
        ->and($opened)->toBeFalse()
        ->and($this->buffer->get('t1')['events'])->toBe([]);
});

it('labels the turn with its feature for usage attribution', function () {
    $this->runner->run(
        turnId: 't1',
        stream: fn (): array => [runnerDelta('hi')],
        mapper: $this->mapper,
        buffer: $this->buffer,
        feature: 'assistant',
    );

    expect(Context::get((string) config('ai-kit.usage.feature_context_key')))->toBe('assistant');
});

it('acts as the given user during the fold and restores the previous guard state', function () {
    $previous = new GenericUser(['id' => 1]);
    $actor = new GenericUser(['id' => 2]);

    Auth::guard()->setUser($previous);

    $seen = null;

    $this->runner->run(
        turnId: 't1',
        stream: function () use (&$seen): array {
            $seen = Auth::user();

            return [runnerDelta('hi')];
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
        actingAs: $actor,
    );

    expect($seen)->toBe($actor)
        ->and(Auth::user())->toBe($previous);
});

it('forgets the guards after the fold when no user was authenticated before', function () {
    $this->runner->run(
        turnId: 't1',
        stream: fn (): array => [runnerDelta('hi')],
        mapper: $this->mapper,
        buffer: $this->buffer,
        actingAs: new GenericUser(['id' => 2]),
    );

    expect(Auth::guard()->hasUser())->toBeFalse();
});

it('restores the guard even when the stream throws', function () {
    $previous = new GenericUser(['id' => 1]);
    Auth::guard()->setUser($previous);

    $this->runner->run(
        turnId: 't1',
        stream: function (): Generator {
            yield runnerDelta('a');

            throw new RuntimeException('boom');
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
        actingAs: new GenericUser(['id' => 2]),
    );

    expect(Auth::user())->toBe($previous);
});

it('finishes a cancelled turn as a completed short turn with the partial text', function () {
    $this->buffer->cancel('t1');

    $outcome = $this->runner->run(
        turnId: 't1',
        // Yield-first: the frame already produced still lands, THEN the
        // stop is noticed on the first poll.
        stream: fn (): array => [runnerDelta('partial'), runnerDelta('never')],
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    expect($outcome->cancelled)->toBeTrue()
        ->and($outcome->failed)->toBeFalse()
        ->and($outcome->result->text)->toBe('partial')
        ->and($outcome->done)->toBe([])
        ->and($this->buffer->get('t1')['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'partial']],
        ]);
});

it('returns a failed outcome carrying the resolved message on a terminal provider error', function () {
    $this->mapper->onError(fn (Error $event): string => 'something went wrong');

    $outcome = $this->runner->run(
        turnId: 't1',
        stream: fn (): array => [
            runnerDelta('half'),
            new Error('e1', 'provider_error', 'upstream exploded', false, 1),
        ],
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    // The error frame is held back like done is; the app writes the
    // terminal itself. Everything before it reached the buffer.
    expect($outcome->failed)->toBeTrue()
        ->and($outcome->failure)->toBe('something went wrong')
        ->and($outcome->exception)->toBeNull()
        ->and($this->buffer->get('t1')['status'])->toBe('running')
        ->and($this->buffer->get('t1')['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'half']],
        ]);
});

it('turns a thrown exception into a failed outcome with the app message and the exception', function () {
    $outcome = $this->runner->run(
        turnId: 't1',
        stream: function (): Generator {
            yield runnerDelta('early ');
            yield runnerDelta('words');

            throw new RuntimeException('secret internals');
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
        failMessage: fn (?Throwable $e): string => 'a generic line',
    );

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->failure)->toBe('a generic line')
        ->and($outcome->exception)->toBeInstanceOf(RuntimeException::class)
        ->and($outcome->exception->getMessage())->toBe('secret internals');

    // Text produced before the crash was flushed to the buffer on the way
    // out — exactly what an uncoalesced fold would have written.
    expect($this->buffer->get('t1')['events'])->toBe([
        ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'early words']],
    ]);
});

it('defaults the failure message to the exception message when no resolver is given', function () {
    $outcome = $this->runner->run(
        turnId: 't1',
        stream: function (): Generator {
            yield from [];

            throw new RuntimeException('raw message');
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    expect($outcome->failure)->toBe('raw message');
});

it('binds ToolProgress for the turn and unbinds it on every path', function () {
    $boundDuring = null;

    $this->runner->run(
        turnId: 't1',
        stream: function () use (&$boundDuring): Generator {
            yield new ToolCall('tc1', new ToolCallData('id1', 'classify', []), 1);

            ToolProgress::current()->report('id1', label: 'working', current: 1, total: 2);
            $boundDuring = $this->buffer->get('t1')['events'] !== [];

            yield new ToolResult('tr1', new ToolResultData('id1', 'classify', [], 'ok'), true, null, 2);

            throw new RuntimeException('and still unbinds');
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    expect($boundDuring)->toBeTrue();

    // After the run (a throwing one, even), reports go nowhere.
    $before = $this->buffer->get('t1')['events'];
    ToolProgress::current()->report('id1', label: 'late');

    expect($this->buffer->get('t1')['events'])->toBe($before);
});

it('routes progress frames to upsert keyed by call id, re-stamping the tool name', function () {
    $outcome = $this->runner->run(
        turnId: 't1',
        stream: function (): Generator {
            yield new ToolCall('tc1', new ToolCallData('id1', 'classify', []), 1);

            // Two reports: the second is final (2/2) so it beats the
            // throttle and REPLACES the first in the log.
            ToolProgress::current()->report('id1', label: 'items', current: 1, total: 2);
            ToolProgress::current()->report('id1', label: 'items', current: 2, total: 2);

            yield new ToolResult('tr1', new ToolResultData('id1', 'classify', [], 'ok'), true, null, 2);
        },
        mapper: $this->mapper,
        buffer: $this->buffer,
    );

    expect($outcome->failed)->toBeFalse();

    $events = $this->buffer->get('t1')['events'];

    // One running entry (the mapper's own frame, upserted over by the
    // progress reports) and one done entry — never a log line per report.
    expect($events)->toHaveCount(2)
        ->and($events[0]['event'])->toBe('tool')
        ->and($events[0]['data'])->toEqual([
            'id' => 'id1',
            'status' => 'running',
            'progress' => ['label' => 'items', 'current' => 2, 'total' => 2],
            'name' => 'classify',
        ])
        ->and($events[1]['data'])->toBe([
            'id' => 'id1',
            'name' => 'classify',
            'status' => 'done',
            'successful' => true,
        ]);
});

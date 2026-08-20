<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Saad\AiKit\Streaming\TurnBuffer;

beforeEach(function () {
    $this->buffer = $this->app->make(TurnBuffer::class);
});

afterEach(fn () => Carbon::setTestNow());

function captureTail(callable $tail): array
{
    ob_start();
    $lastSeq = $tail();

    return [ob_get_clean(), $lastSeq];
}

/**
 * A buffer whose tail never sleeps, with a short stale window and the
 * smallest pages — the stale tests travel past the window, the paging
 * tests cross boundaries with a handful of events.
 */
function fastBuffer(int $staleAfterSeconds = 300, bool $staleTrailingDone = false, int $pageSize = 64): TurnBuffer
{
    return new TurnBuffer(
        Cache::store('array'),
        pollIntervalMs: 0,
        pageSize: $pageSize,
        staleAfterSeconds: $staleAfterSeconds,
        staleTrailingDone: $staleTrailingDone,
    );
}

it('tails a finished turn as id-carrying SSE frames and closes after draining', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'hi']);
    $this->buffer->finish('t1', ['ok' => true]);

    [$out, $lastSeq] = captureTail(fn () => $this->buffer->tail('t1'));

    expect($out)->toBe(
        "id: 1\nevent: delta\ndata: {\"text\":\"hi\"}\n\n".
        "id: 2\nevent: done\ndata: {\"ok\":true}\n\n"
    )->and($lastSeq)->toBe(2);
});

it('resumes after a cursor without duplicating events', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);
    $this->buffer->append('t1', 'delta', ['text' => 'b']);
    $this->buffer->finish('t1');

    [$out, $lastSeq] = captureTail(fn () => $this->buffer->tail('t1', after: 2));

    expect($out)->toBe("id: 3\nevent: done\ndata: []\n\n")
        ->and($lastSeq)->toBe(3);
});

it('decorates event payloads with record state at emit time', function () {
    $this->buffer->start('t1');
    $this->buffer->finish('t1', ['charged' => 1], ['conversation_id' => 'c9']);

    [$out] = captureTail(fn () => $this->buffer->tail(
        't1',
        decorate: fn (string $event, array $data, array $record) => $event === 'done'
            ? $data + ['conversation_id' => $record['meta']['conversation_id']]
            : $data,
    ));

    expect($out)->toBe("id: 1\nevent: done\ndata: {\"charged\":1,\"conversation_id\":\"c9\"}\n\n");
});

it('drains a failed turn to its terminal error frame and closes', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'partial']);
    $this->buffer->fail('t1', 'broke');

    [$out] = captureTail(fn () => $this->buffer->tail('t1'));

    // `error` is the terminal frame — no `done` trails it.
    expect($out)->toBe(
        "id: 1\nevent: delta\ndata: {\"text\":\"partial\"}\n\n".
        "id: 2\nevent: error\ndata: {\"message\":\"broke\"}\n\n"
    );
});

it('emits a keepalive comment when a running turn goes idle', function () {
    $this->buffer->start('t1');

    [$out, $lastSeq] = captureTail(fn () => $this->buffer->tail('t1', maxSeconds: 0, keepaliveSeconds: 0));

    expect($out)->toBe(": keepalive\n\n")
        ->and($lastSeq)->toBe(0);
});

it('stops at the deadline on a still-running turn', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'partial']);

    [$out, $lastSeq] = captureTail(fn () => $this->buffer->tail('t1', maxSeconds: 0));

    // Emits what exists, then hits the deadline instead of blocking — the
    // client's EventSource reconnects with cursor=1.
    expect($out)->toBe("id: 1\nevent: delta\ndata: {\"text\":\"partial\"}\n\n")
        ->and($lastSeq)->toBe(1);
});

it('returns immediately for an unknown or expired turn', function () {
    [$out, $lastSeq] = captureTail(fn () => $this->buffer->tail('ghost', after: 4));

    expect($out)->toBe('')
        ->and($lastSeq)->toBe(4);
});

// --- Paging ---------------------------------------------------------------

it('emits events in order across page boundaries and resumes from a later page', function () {
    $buffer = fastBuffer(pageSize: 2);

    $buffer->start('t1');
    foreach (range(1, 5) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }
    $buffer->finish('t1');

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1'));

    expect($out)->toBe(
        "id: 1\nevent: delta\ndata: {\"i\":1}\n\n".
        "id: 2\nevent: delta\ndata: {\"i\":2}\n\n".
        "id: 3\nevent: delta\ndata: {\"i\":3}\n\n".
        "id: 4\nevent: delta\ndata: {\"i\":4}\n\n".
        "id: 5\nevent: delta\ndata: {\"i\":5}\n\n".
        "id: 6\nevent: done\ndata: []\n\n"
    )->and($lastSeq)->toBe(6);

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1', after: 4));

    expect($out)->toBe(
        "id: 5\nevent: delta\ndata: {\"i\":5}\n\n".
        "id: 6\nevent: done\ndata: []\n\n"
    )->and($lastSeq)->toBe(6);
});

// --- Stale detection ------------------------------------------------------

it('fails a running turn whose heartbeat went stale and emits exactly one terminal', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1', ['user_id' => 7]);
    $buffer->append('t1', 'delta', ['text' => 'partial']);

    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0));

    $message = __('ai-kit::streaming.stale');
    $record = $buffer->get('t1');

    expect($message)->not->toBe('ai-kit::streaming.stale')
        ->and($out)->toBe(
            "id: 1\nevent: delta\ndata: {\"text\":\"partial\"}\n\n".
            "id: 2\nevent: error\ndata: {\"message\":\"{$message}\"}\n\n"
        )
        ->and($lastSeq)->toBe(2)
        ->and($record['status'])->toBe('failed')
        ->and($record['meta'])->toBe(['user_id' => 7, 'stale' => true, 'error' => $message])
        ->and(array_column($record['events'], 'event'))->toBe(['delta', 'error']);

    // A second tailer (or a reconnect) just drains the failure — no second terminal.
    [$out] = captureTail(fn () => $buffer->tail('t1', after: 1));

    expect($out)->toBe("id: 2\nevent: error\ndata: {\"message\":\"{$message}\"}\n\n");
});

it('uses the caller-supplied stale message', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    [$out] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, staleMessage: 'انقطع الاتصال بالمساعد'));

    expect($out)->toBe("id: 1\nevent: error\ndata: {\"message\":\"انقطع الاتصال بالمساعد\"}\n\n")
        ->and($buffer->get('t1')['meta']['error'])->toBe('انقطع الاتصال بالمساعد');
});

it('trails the stale error with a done when the buffer is built for such clients', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300, staleTrailingDone: true);

    $buffer->start('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, staleMessage: 'stale'));

    expect($out)->toBe(
        "id: 1\nevent: error\ndata: {\"message\":\"stale\"}\n\n".
        "id: 2\nevent: done\ndata: []\n\n"
    )->and($lastSeq)->toBe(2)
        ->and($buffer->status('t1'))->toBe('failed');
});

it('leaves a silent but heartbeating turn alone — keepalive comments only', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1');

    // Inside a long tool call: no events, but the producer keeps touching.
    Carbon::setTestNow(Carbon::now()->addSeconds(250));
    $buffer->touch('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(250));

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, keepaliveSeconds: 0));

    expect($out)->toBe(": keepalive\n\n")
        ->and($lastSeq)->toBe(0)
        ->and($buffer->status('t1'))->toBe('running');
});

it('does not declare a turn stale inside the window', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(300));

    [$out] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, keepaliveSeconds: 0));

    expect($out)->toBe(": keepalive\n\n")
        ->and($buffer->status('t1'))->toBe('running');
});

it('a producer that wakes up after the stale terminal cannot write past it', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(301));
    captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, staleMessage: 'stale'));

    $buffer->append('t1', 'delta', ['text' => 'late']);
    $buffer->finish('t1', ['ok' => true]);

    $record = $buffer->get('t1');

    expect($record['status'])->toBe('failed')
        ->and(array_column($record['events'], 'event'))->toBe(['error']);
});

it('defers to a sibling tailer that already claimed the stale terminal', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    $buffer->start('t1');
    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    // Another tailer won the claim a moment ago and has not written yet.
    Cache::store('array')->add('ai-kit:turn:t1:stale', true, 60);

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0));

    // We wrote nothing and bowed out at the deadline; the record is untouched.
    expect($out)->toBe('')
        ->and($lastSeq)->toBe(0)
        ->and($buffer->status('t1'))->toBe('running');
});

it('disables the stale check when the window is zero', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 0);

    $buffer->start('t1');

    // Far beyond any stale window, but short of the record's TTL.
    Carbon::setTestNow(Carbon::now()->addHour());

    [$out] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, keepaliveSeconds: 0));

    expect($out)->toBe(": keepalive\n\n")
        ->and($buffer->status('t1'))->toBe('running');
});

it('never declares a record without a heartbeat stale on its first poll', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    // Written by the pre-heartbeat kit, long ago.
    Cache::store('array')->put('ai-kit:turn:old', [
        'status' => 'running',
        'cursor' => 1,
        'events' => [['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'a']]],
        'meta' => [],
    ], 7200);

    Carbon::setTestNow(Carbon::now()->addHour());

    [$out, $lastSeq] = captureTail(fn () => $buffer->tail('old', maxSeconds: 0));

    expect($out)->toBe("id: 1\nevent: delta\ndata: {\"text\":\"a\"}\n\n")
        ->and($lastSeq)->toBe(1)
        ->and($buffer->status('old'))->toBe('running');
});

it('falls back to started_at when a record has no heartbeat yet', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = fastBuffer(staleAfterSeconds: 300);

    Cache::store('array')->put('ai-kit:turn:t1', [
        'status' => 'running',
        'cursor' => 0,
        'meta' => [],
        'started_at' => Carbon::now()->getTimestamp(),
        'page_size' => 64,
    ], 7200);

    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    [$out] = captureTail(fn () => $buffer->tail('t1', maxSeconds: 0, staleMessage: 'stale'));

    expect($out)->toBe("id: 1\nevent: error\ndata: {\"message\":\"stale\"}\n\n")
        ->and($buffer->status('t1'))->toBe('failed');
});

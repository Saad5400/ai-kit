<?php

use Saad\AiKit\Streaming\TurnBuffer;

beforeEach(function () {
    $this->buffer = $this->app->make(TurnBuffer::class);
});

function captureTail(callable $tail): array
{
    ob_start();
    $lastSeq = $tail();

    return [ob_get_clean(), $lastSeq];
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

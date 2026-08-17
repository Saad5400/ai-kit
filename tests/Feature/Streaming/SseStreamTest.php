<?php

use Saad\AiKit\Streaming\SseStream;

beforeEach(function () {
    $this->stream = $this->app->make(SseStream::class);
});

function captureSse(callable $write): string
{
    ob_start();
    $write();

    return ob_get_clean();
}

it('writes the canonical event frame', function () {
    $out = captureSse(fn () => $this->stream->emit('delta', ['text' => 'hi']));

    expect($out)->toBe("event: delta\ndata: {\"text\":\"hi\"}\n\n");
});

it('leads the frame with an id line when a sequence number is given', function () {
    $out = captureSse(fn () => $this->stream->emit('done', ['ok' => true], 7));

    expect($out)->toBe("id: 7\nevent: done\ndata: {\"ok\":true}\n\n");
});

it('keeps unicode and slashes literal in the data line', function () {
    $out = captureSse(fn () => $this->stream->emit('delta', ['text' => 'مرحبا /path']));

    expect($out)->toBe("event: delta\ndata: {\"text\":\"مرحبا /path\"}\n\n");
});

it('writes keepalive comment frames', function () {
    $out = captureSse(fn () => $this->stream->comment());

    expect($out)->toBe(": keepalive\n\n");
});

it('writes custom comment text', function () {
    $out = captureSse(fn () => $this->stream->comment('ping'));

    expect($out)->toBe(": ping\n\n");
});

it('exposes the canonical SSE header set', function () {
    expect(SseStream::headers())->toBe([
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache, no-transform',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ]);
});

it('short-circuits connection and time-limit plumbing under unit tests', function () {
    // Neither call may touch the real request lifecycle in a test run; the
    // frames above already prove emit still echoes for capture.
    $this->stream->extendTimeLimit(90);

    expect($this->stream->aborted())->toBeFalse();
});

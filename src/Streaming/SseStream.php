<?php

namespace Saad\AiKit\Streaming;

/**
 * Writes Server-Sent-Events frames to the response output. This is the one
 * place the wire format lives — the inline streamed-response path (LLM call
 * inside the request, uqucc style) and the buffer-tailing resumable path
 * (catodemy / s-grade style) both emit through it, so every app produces
 * byte-identical frames: `event: NAME\ndata: {json}\n\n`, optionally led by
 * an `id:` line carrying the event's sequence number for resumability.
 *
 * Flushing short-circuits under unit tests: frames are still echoed (so a
 * streamed response's output can be captured and asserted) but nothing is
 * flushed and no time limits are touched.
 */
class SseStream
{
    /**
     * @param  bool|null  $flushOutput  null resolves lazily to "not running unit tests".
     */
    public function __construct(protected ?bool $flushOutput = null) {}

    /**
     * The canonical SSE response header set. `no-transform` and
     * `X-Accel-Buffering: no` keep proxies (nginx/Traefik) from buffering
     * the stream shut.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Write one SSE event frame and flush it to the client immediately.
     * When a sequence number is given it rides the `id:` line, so the
     * browser's EventSource sets `Last-Event-ID` automatically on reconnect.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(string $event, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            echo 'id: '.$id."\n";
        }

        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        $this->flush();
    }

    /**
     * Write an SSE comment frame — the keepalive that stops intermediary
     * proxies from buffering an idle stream shut, and lets the server notice
     * a closed socket promptly.
     */
    public function comment(string $text = 'keepalive'): void
    {
        echo ': '.$text."\n\n";

        $this->flush();
    }

    /**
     * Whether the client went away. Always false under unit tests.
     */
    public function aborted(): bool
    {
        if (! $this->shouldFlush()) {
            return false;
        }

        return connection_aborted() === 1;
    }

    /**
     * Give the streaming request room to outlive the default PHP time limit
     * (the inline path calls this with the model timeout plus headroom).
     * A no-op under unit tests.
     */
    public function extendTimeLimit(int $seconds): void
    {
        if ($this->shouldFlush()) {
            set_time_limit($seconds);
        }
    }

    /**
     * Push buffered output to the client immediately. Under Octane or
     * RoadRunner there is no output-buffering stack, so the ob_* calls are
     * guarded.
     */
    protected function flush(): void
    {
        if (! $this->shouldFlush()) {
            return;
        }

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    protected function shouldFlush(): bool
    {
        return $this->flushOutput ??= ! app()->runningUnitTests();
    }
}

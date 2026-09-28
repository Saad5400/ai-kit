<?php

namespace Saad\AiKit\Streaming;

use Throwable;

/**
 * What one {@see TurnRunner} run came to. The runner never writes the
 * turn's terminal event — the app reads this and writes its own
 * `finish()` / `fail()` payload, because a completion payload (credit
 * outcome, grounding, persisted message id) only exists app-side and only
 * after the fold — so this object is the whole handoff.
 *
 * `cancelled` and `failed` are independent axes: a stopped turn COMPLETES
 * with partial text (`cancelled: true, failed: false` — a stop is a short
 * turn, not an error), while `failed` covers the kill switch, a terminal
 * provider `error`, and a thrown exception. `failure` is the resolved,
 * app-facing message for the failed cases; `exception` is only set when a
 * throw ended the turn; `done` is the mapper's assembled done payload
 * (from `doneUsing`), for apps that build on it rather than replacing it.
 * `failureCode` is the kit's machine-readable reason ({@see ErrorCode}) for
 * the failed cases — pass it to `TurnBuffer::fail(..., code: ...)` so the
 * wire `error` carries it.
 *
 * A failed turn's `result` is PARTIAL, never empty: the text, tool calls
 * and tool results the turn produced before it died, whether the provider
 * reported the error in the stream or the stream threw.
 */
final readonly class TurnOutcome
{
    /**
     * @param  array<string, mixed>|null  $done
     */
    public function __construct(
        public StreamResult $result,
        public bool $cancelled = false,
        public bool $failed = false,
        public ?string $failure = null,
        public ?Throwable $exception = null,
        public ?array $done = null,
        public ?string $failureCode = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $done
     */
    public static function completed(StreamResult $result, ?array $done = null, bool $cancelled = false): self
    {
        return new self($result, cancelled: $cancelled, done: $done);
    }

    public static function failed(StreamResult $result, string $failure, ?Throwable $exception = null, ?string $code = null): self
    {
        $result->failed = true;

        return new self($result, failed: true, failure: $failure, exception: $exception, failureCode: $code);
    }
}

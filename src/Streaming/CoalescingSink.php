<?php

namespace Saad\AiKit\Streaming;

use Closure;
use Illuminate\Support\Carbon;

/**
 * The stage between the mapper's fold and the app's sink that merges runs
 * of `delta` (and, separately, `reasoning`) frames before they reach the
 * wire. It exists for the buffered path: a 10-minute turn is 15–20k
 * one-token frames, and every one of them was a read-modify-write of the
 * turn record — the log the SSE tail replays grew by the token.
 *
 * Only text is held, and never across another kind of event: a `tool`,
 * `approval`, `question` or terminal frame flushes the held text FIRST, so
 * the order the sink observes is exactly the uncoalesced order with
 * adjacent text frames concatenated. Two text frames of different kinds
 * never merge — a `delta` closes a held `reasoning` run and vice versa,
 * which is what keeps the client's thinking-block bracket (opened on the
 * first `reasoning`, closed on the first `delta`) intact.
 *
 * Held text is released when the next text frame finds the window elapsed,
 * when the held run reaches `$maxChars`, on a kind change, and when the
 * fold ends ({@see flush()}). The stage has no timer of its own and can
 * only act when an event arrives, so the latency it adds is bounded by one
 * inter-token gap rather than by the window — and it sits AFTER the
 * mapper's bookkeeping, so {@see StreamResult::$text} is identical with or
 * without it.
 *
 * Only the bare `{text}` shape is merged; a frame an app hook emitted
 * under the same name with extra fields passes through untouched, since
 * there is no honest way to merge two payloads the stage does not
 * understand.
 *
 * @internal
 */
final class CoalescingSink
{
    private string $heldEvent = '';

    private string $held = '';

    private int $heldChars = 0;

    private float $heldSince = 0.0;

    public function __construct(
        private readonly Closure $emit,
        private readonly int $windowMs,
        private readonly int $maxChars,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(string $event, array $data): void
    {
        if (($event === 'delta' || $event === 'reasoning') && array_keys($data) === ['text'] && is_string($data['text'])) {
            $this->hold($event, $data['text']);

            return;
        }

        $this->flush();

        ($this->emit)($event, $data);
    }

    /**
     * Release whatever is held, as one frame of its kind. Called by the
     * mapper when the fold ends — on the normal exit, on a terminal error
     * and when the stream throws, so text the model produced before a
     * crash reaches the sink exactly as it would have uncoalesced.
     */
    public function flush(): void
    {
        if ($this->held === '') {
            return;
        }

        [$event, $text] = [$this->heldEvent, $this->held];

        $this->heldEvent = '';
        $this->held = '';
        $this->heldChars = 0;

        ($this->emit)($event, ['text' => $text]);
    }

    private function hold(string $event, string $text): void
    {
        if ($text === '') {
            return;
        }

        $now = $this->now();

        // A stale or foreign run goes out before this text starts (or
        // extends) a fresh one — the window is measured from the run's
        // FIRST frame, so a long run is cut into window-sized pieces rather
        // than ridden to maxChars.
        if ($this->held !== '' && ($this->heldEvent !== $event || $now - $this->heldSince >= $this->windowMs)) {
            $this->flush();
        }

        if ($this->held === '') {
            $this->heldEvent = $event;
            $this->heldSince = $now;
        }

        $this->held .= $text;
        $this->heldChars += mb_strlen($text);

        if ($this->heldChars >= $this->maxChars) {
            $this->flush();
        }
    }

    /**
     * Milliseconds on Laravel's clock, so tests drive the window with
     * `Carbon::setTestNow()` instead of sleeping.
     */
    private function now(): float
    {
        return Carbon::now()->getPreciseTimestamp(3);
    }
}

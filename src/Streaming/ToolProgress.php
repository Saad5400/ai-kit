<?php

namespace Saad\AiKit\Streaming;

use Closure;
use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Support\TurnContext;

/**
 * The seam a tool reports mid-call progress through — the answer to a tool
 * that loops over 40 items for six minutes showing nothing but a spinner.
 *
 * A tool runs deep inside laravel/ai's tool loop, with no path to the
 * turn's sink or buffer, so the binding is STATIC and per-turn: the
 * {@see TurnRunner} binds before the fold and unbinds in its finally, one
 * turn per process — the same shape (and the same Octane-safety argument)
 * as {@see TurnContext}. A tool always just calls
 * {@see current()}: when nothing is bound (an MCP call, a plain test) it
 * gets a no-op instance, reports into the void and is never cancelled, so
 * tools need no environment checks.
 *
 * Emits `tool {id, status: 'running', progress: {label?, percent?,
 * current?, total?}}` through the sink — deliberately WITHOUT `name`: the
 * client already has the name from the mapper's own `running` event for
 * the same id and merges by id. On the buffered path the runner's sink
 * routes these to {@see TurnBuffer::upsert()}, so a six-minute loop's 300
 * updates stay ONE log entry.
 *
 * Emits are throttled to one per second PER CALL ID; a skipped report
 * still touches the buffer's heartbeat, so a tool reporting every 100 ms
 * keeps the turn visibly alive without growing the log. The throttle
 * yields to anything the user must not miss: a label change, and the
 * final state (`current === total`, or `percent >= 100`) — a loop's last
 * update is never the one the throttle ate.
 *
 * Cancellation is the read side of the same seam: {@see isCancelled()}
 * polls the buffer's cancel flag (via the bound closure), throttled to one
 * cache read per second, and is sticky once true. This is what lets a stop
 * land INSIDE a tool instead of waiting for the tool to return.
 */
final class ToolProgress
{
    private static ?self $bound = null;

    /** @var array<string, array{at: float, label: ?string}> */
    private array $lastEmit = [];

    private float $cancelCheckedAt = 0.0;

    private bool $cancelled = false;

    private function __construct(
        private readonly ?string $turnId = null,
        private readonly ?Closure $sink = null,
        private readonly ?TurnBuffer $buffer = null,
        private readonly ?Closure $cancelledProbe = null,
    ) {}

    /**
     * Bind for the current turn — the {@see TurnRunner} does this; the sink
     * is the same callable the mapper emits through, so progress frames
     * take the exact route every other wire event takes. `$cancelled`
     * defaults to the buffer's own cancel flag when a buffer is given.
     */
    public static function bind(string $turnId, callable $sink, ?TurnBuffer $buffer = null, ?Closure $cancelled = null): void
    {
        self::$bound = new self(
            $turnId,
            Closure::fromCallable($sink),
            $buffer,
            $cancelled ?? ($buffer !== null ? fn (): bool => $buffer->isCancelled($turnId) : null),
        );
    }

    public static function unbind(): void
    {
        self::$bound = null;
    }

    /**
     * The turn's binding, or a no-op instance when nothing is bound — so a
     * tool reports unconditionally and behaves the same under MCP, in a
     * test, or mid-turn.
     */
    public static function current(): self
    {
        return self::$bound ?? new self;
    }

    /**
     * Whether the user stopped this turn. Throttled to one probe per
     * second — a per-item loop check must never become a per-item cache
     * read — and sticky: once a stop is seen it stays seen.
     */
    public function isCancelled(): bool
    {
        if ($this->cancelled || $this->cancelledProbe === null) {
            return $this->cancelled;
        }

        $now = $this->now();

        if ($now - $this->cancelCheckedAt < 1000.0) {
            return false;
        }

        $this->cancelCheckedAt = $now;

        return $this->cancelled = (bool) ($this->cancelledProbe)();
    }

    /**
     * Report progress for one tool call. Everything is optional: a bare
     * label ("uploading…"), a percent, a current/total pair, or any mix —
     * the chip renders whatever is present.
     */
    public function report(string $toolCallId, ?string $label = null, ?float $percent = null, ?int $current = null, ?int $total = null): void
    {
        if ($this->sink === null || $toolCallId === '') {
            return;
        }

        $now = $this->now();
        $last = $this->lastEmit[$toolCallId] ?? null;

        $final = ($current !== null && $total !== null && $current >= $total)
            || ($percent !== null && $percent >= 100);

        $due = $last === null
            || $final
            || $label !== $last['label']
            || $now - $last['at'] >= 1000.0;

        if (! $due) {
            // Skipped on the wire, but still proof of life: the tool is
            // clearly working, so the stale watchdog must not fire.
            if ($this->turnId !== null) {
                $this->buffer?->touch($this->turnId);
            }

            return;
        }

        $this->lastEmit[$toolCallId] = ['at' => $now, 'label' => $label];

        $progress = array_filter([
            'label' => $label,
            'percent' => $percent !== null ? max(0.0, min(100.0, $percent)) : null,
            'current' => $current,
            'total' => $total,
        ], fn (mixed $value): bool => $value !== null);

        ($this->sink)('tool', [
            'id' => $toolCallId,
            'status' => 'running',
            'progress' => $progress,
        ]);
    }

    /**
     * A reporter pinned to one call id — what a tool actually holds while
     * it works. Accepts the tool's own {@see Request} (the id comes from
     * {@see Request::toolCallId()}); a request without an id yields a
     * reporter that reports nothing but still answers {@see isCancelled()}.
     */
    public function for(Request|string $call): ToolProgressReporter
    {
        return new ToolProgressReporter($this, $call instanceof Request ? $call->toolCallId() : $call);
    }

    /**
     * Milliseconds on Laravel's clock, so tests drive the throttle with
     * `Carbon::setTestNow()` instead of sleeping.
     */
    private function now(): float
    {
        return Carbon::now()->getPreciseTimestamp(3);
    }
}

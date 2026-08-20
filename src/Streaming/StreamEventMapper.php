<?php

namespace Saad\AiKit\Streaming;

use Closure;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * Folds a laravel/ai stream (an iterable of streaming events) into the
 * canonical wire events the three apps converged on: `delta {text}` for
 * model text, `reasoning {text}` for thinking, `tool {id, name, status}`
 * for tool progress, `error {message}` on failure, and a terminal
 * `done {...}` whose payload the caller assembles from the collected
 * {@see StreamResult}.
 *
 * TERMINAL-EVENT CONTRACT (identical here and on the buffered path through
 * {@see TurnBuffer}): a turn ends with EXACTLY ONE terminal event —
 * `done {...}` when it completed, or `error {message}` when it did not.
 * `error` is terminal; no `done` ever follows it. A client that tears its
 * stream down on either event therefore behaves the same whether the turn
 * was streamed inline or replayed out of a buffer.
 *
 * REASONING CONTRACT (owner decision #18, ruled 2026-08-18): reasoning is
 * emitted BY DEFAULT as bare `reasoning {text}` deltas — there are no
 * start/end wire events. A client opens its thinking block on the first
 * `reasoning` and closes it on the first following `delta`, `tool` or
 * terminal event; a turn may reopen it if the model thinks again between
 * tool calls. Keeping the wire eventless here means a replayed buffer needs
 * no bracket repair.
 *
 * TOOL CONTRACT (same ruling): a tool call emits
 * `tool {id, name, status: 'running'}` and its result
 * `tool {id, name, status: 'done', successful}`, correlated by `id`.
 * Arguments and results deliberately NEVER reach the wire by default —
 * these apps are public-facing and tool payloads carry retrieved records.
 * An app that wants richer payloads opts in with an explicit
 * `on(ToolCall::class, ...)` hook and owns the disclosure decision.
 *
 * A call that pauses for approval emits its `running` event and no `done`
 * — the provider yields the ToolCall before the loop decides the call
 * needs approving. The `approval` card that follows carries the SAME id
 * (vendor builds `PendingApproval` from the tool call), so the client
 * folds the running chip into the card rather than stranding a spinner,
 * and the resumed turn's `done` lands on the right chip.
 *
 * ORDER: reasoning and tool events are emitted at the point they occur in
 * the stream, never queued behind the text pipeline. A transformer holding
 * text back (uqucc's link guard) therefore releases that text AFTER a tool
 * event that arrived while it was held — which is what the UI wants: the
 * chip appears when the tool runs, not when the prose catches up.
 *
 * Where the events go is the caller's business — the sink is a plain
 * `(string $event, array $data)` callable, so the same mapper drives an
 * inline SSE response (`fn ($e, $d) => $sse->emit($e, $d)`). For the
 * resumable background-job path use {@see runIntoBuffer()}, which keeps the
 * buffer the sole author of the turn's terminal event.
 *
 * CANCELLATION is deliberately not a hook here. The fold has no business
 * deciding when a turn should stop; a generator composed IN FRONT of the
 * mapper does, and it stops the provider stream itself rather than merely
 * silencing its events:
 *
 *     $untilCancelled = function (iterable $stream) use ($buffer, $turnId): Generator {
 *         foreach ($stream as $event) {
 *             if ($buffer->isCancelled($turnId)) {
 *                 return;
 *             }
 *
 *             yield $event;
 *         }
 *     };
 *
 *     $mapper->runIntoBuffer($untilCancelled($stream), $buffer, $turnId, $meta);
 *
 * A cancelled stream simply ENDS, so the fold takes its normal exit: held
 * text flushes, `beforeDone` hooks run, and the turn finishes on `done`
 * with whatever it produced — a stop is a completed short turn, not an
 * error.
 *
 * Extension points:
 * - {@see transformText}: a pipeline on the text channel. Transformers may
 *   hold text back mid-stream (uqucc's streaming link guard buffers until a
 *   candidate link completes); held tails are flushed through the rest of
 *   the pipeline when the stream ends.
 * - {@see on}: intercept any stream event class and emit whatever the app's
 *   contract needs (approval, question, step, segment, citations, ...). The hook
 *   replaces the default emission for that event — including the default
 *   `reasoning` / `tool` emissions; bookkeeping (tool calls / results,
 *   usage) is still collected first.
 * - {@see onReasoning}: sugar for `on(ReasoningDelta::class, ...)` — shapes
 *   the reasoning channel instead of the default `reasoning {text}`.
 * - {@see withoutReasoning} / {@see withoutToolEvents}: drop those default
 *   emissions entirely (an app whose UI has nowhere to put them).
 * - {@see beforeDone}: runs after the stream drains and the text pipeline
 *   flushes, before `done` — where post-stream events like `citations` go.
 */
class StreamEventMapper
{
    /**
     * Tri-state coalescing switch: null means "the path's default" — OFF on
     * inline {@see run()} so a directly-streamed app keeps its per-token
     * feel, ON on the buffered path ({@see runBuffered()}, and therefore
     * {@see runIntoBuffer()} and the {@see TurnRunner}) where every frame
     * is a cache write. {@see coalesce()} / {@see withoutCoalescing()}
     * override either default.
     */
    protected ?bool $coalesce = null;

    protected int $coalesceWindowMs = 100;

    protected int $coalesceMaxChars = 400;

    /** @var list<TextTransformer> */
    protected array $transformers = [];

    /** @var list<array{class-string<StreamEvent>, Closure}> */
    protected array $hooks = [];

    /** @var list<Closure> */
    protected array $beforeDone = [];

    protected Closure $doneUsing;

    protected Closure $errorMessage;

    protected bool $reasoning = true;

    protected bool $toolEvents = true;

    public function __construct()
    {
        $this->doneUsing = fn (StreamResult $result): array => [];
        $this->errorMessage = fn (Error $event): string => $event->message;
    }

    /**
     * Append a transformer to the text pipeline. A plain closure
     * `fn (string $delta): string` is wrapped as a stateless transformer.
     */
    public function transformText(TextTransformer|Closure $transformer): static
    {
        $this->transformers[] = $transformer instanceof TextTransformer
            ? $transformer
            : new class($transformer) implements TextTransformer
            {
                public function __construct(private readonly Closure $map) {}

                public function push(string $delta): string
                {
                    return ($this->map)($delta);
                }

                public function flush(): string
                {
                    return '';
                }
            };

        return $this;
    }

    /**
     * Intercept a stream event class (instanceof match, so a parent class
     * catches subclasses; first registered match wins). The hook receives
     * `($event, callable $emit, StreamResult $result)` and replaces the
     * default handling for that event. A hook on {@see Error} still marks
     * the result failed and ends the fold — unless it returns exactly
     * `true`, which continues past a recoverable failure.
     *
     * @param  class-string<StreamEvent>  $eventClass
     */
    public function on(string $eventClass, callable $hook): static
    {
        $this->hooks[] = [$eventClass, Closure::fromCallable($hook)];

        return $this;
    }

    /**
     * Shape the reasoning channel yourself, replacing the default
     * `reasoning {text}` emission. The handler receives
     * `(ReasoningDelta $event, callable $emit)`.
     */
    public function onReasoning(callable $handler): static
    {
        return $this->on(
            ReasoningDelta::class,
            fn (ReasoningDelta $event, callable $emit) => $handler($event, $emit),
        );
    }

    /**
     * Stop emitting the default `reasoning {text}` deltas.
     */
    public function withoutReasoning(): static
    {
        $this->reasoning = false;

        return $this;
    }

    /**
     * Stop emitting the default `tool {id, name, status, ...}` events.
     */
    public function withoutToolEvents(): static
    {
        $this->toolEvents = false;

        return $this;
    }

    /**
     * Resolve the wire `error` message from the provider's {@see Error}
     * event — apps typically replace it with a generic, localized line
     * rather than exposing provider internals.
     */
    public function onError(callable $resolver): static
    {
        $this->errorMessage = Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Run after the stream drains, before `done` — receives
     * `(StreamResult $result, callable $emit)`.
     */
    public function beforeDone(callable $callback): static
    {
        $this->beforeDone[] = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Assemble the terminal `done` payload from the collected result.
     */
    public function doneUsing(callable $payload): static
    {
        $this->doneUsing = Closure::fromCallable($payload);

        return $this;
    }

    /**
     * Merge runs of consecutive `delta` (and, separately, `reasoning`)
     * frames before they reach the sink: a run is released when `$windowMs`
     * elapses since its first frame, when it reaches `$maxChars`, when an
     * event of another kind arrives (which always flushes FIRST, so wire
     * order is unchanged), and when the fold ends. Implemented as a stage
     * in front of the sink — never inside the {@see TextTransformer}
     * pipeline — so {@see StreamResult::$text} is byte-identical with or
     * without it. See {@see CoalescingSink} for the exact semantics.
     */
    public function coalesce(int $windowMs = 100, int $maxChars = 400): static
    {
        $this->coalesce = true;
        $this->coalesceWindowMs = $windowMs;
        $this->coalesceMaxChars = $maxChars;

        return $this;
    }

    /**
     * Emit every text frame as it comes, even on the buffered path — for
     * an app that wants its replay log token-exact and accepts the write
     * amplification.
     */
    public function withoutCoalescing(): static
    {
        $this->coalesce = false;

        return $this;
    }

    /**
     * Fold the stream into wire events on the sink. On a terminal error the
     * fold stops after emitting `error` — no `done` is emitted, mirroring
     * the apps' contract (the client treats `error` as terminal).
     *
     * Coalescing is OFF here unless the app called {@see coalesce()}: an
     * inline SSE response wants each token on the wire the moment it
     * exists.
     *
     * @param  iterable<StreamEvent>  $stream
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    public function run(iterable $stream, callable $emit): StreamResult
    {
        return $this->mapped($stream, $emit, $this->coalesce === true);
    }

    /**
     * The buffered-path twin of {@see run()}: the same fold, but coalescing
     * defaults ON because here every frame becomes a {@see TurnBuffer}
     * write and the log it grows is replayed to every resuming client.
     * {@see runIntoBuffer()} and the {@see TurnRunner} both fold through
     * this; {@see withoutCoalescing()} opts out.
     *
     * @param  iterable<StreamEvent>  $stream
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    public function runBuffered(iterable $stream, callable $emit): StreamResult
    {
        return $this->mapped($stream, $emit, $this->coalesce ?? true);
    }

    /**
     * @param  iterable<StreamEvent>  $stream
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    protected function mapped(iterable $stream, callable $emit, bool $coalesce): StreamResult
    {
        $result = new StreamResult;

        $sink = $coalesce
            ? new CoalescingSink(Closure::fromCallable($emit), $this->coalesceWindowMs, $this->coalesceMaxChars)
            : null;

        // The flush rides a finally so text the model produced before a
        // mid-stream throw still reaches the sink — uncoalesced, those
        // frames had already been emitted by the time the throw landed.
        try {
            $this->fold($stream, $sink ?? $emit, $result);
        } finally {
            $sink?->flush();
        }

        return $result;
    }

    /**
     * @param  iterable<StreamEvent>  $stream
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    protected function fold(iterable $stream, callable $emit, StreamResult $result): void
    {
        foreach ($stream as $event) {
            $this->collect($event, $result);

            if (($hook = $this->hookFor($event)) !== null) {
                $continue = $hook($event, $emit, $result);

                if ($event instanceof Error) {
                    $result->error = $event;

                    if ($continue !== true) {
                        $result->failed = true;

                        return;
                    }
                }

                continue;
            }

            if ($event instanceof TextDelta) {
                $this->emitText($this->pushText($event->delta), $emit, $result);
            } elseif ($event instanceof ReasoningDelta) {
                if ($this->reasoning && $event->delta !== '') {
                    $emit('reasoning', ['text' => $event->delta]);
                }
            } elseif ($event instanceof ToolCall) {
                if ($this->toolEvents) {
                    $emit('tool', [
                        'id' => $event->toolCall->id,
                        'name' => $event->toolCall->name,
                        'status' => 'running',
                    ]);
                }
            } elseif ($event instanceof ToolResult) {
                if ($this->toolEvents) {
                    $emit('tool', [
                        'id' => $event->toolResult->id,
                        'name' => $event->toolResult->name,
                        'status' => 'done',
                        'successful' => $event->successful,
                    ]);
                }
            } elseif ($event instanceof Error) {
                $result->failed = true;
                $result->error = $event;

                $emit('error', ['message' => ($this->errorMessage)($event)]);

                return;
            }
        }

        $this->emitText($this->flushText(), $emit, $result);

        foreach ($this->beforeDone as $callback) {
            $callback($result, $emit);
        }

        $emit('done', ($this->doneUsing)($result));
    }

    /**
     * Fold the stream into a resumable {@see TurnBuffer} turn (the
     * background-job path). Non-terminal events are appended as they are
     * produced; the turn's ONE terminal event is written through the buffer
     * itself — `finish()` with the assembled `done` payload and `$meta`, or
     * `fail()` with the error message and no `done` — so the buffered and
     * inline paths emit the same sequence for the same stream.
     *
     * The turn must already have been started; `$meta` is folded into the
     * record either way (conversation id, final message, credit outcome).
     *
     * `$meta` may be a Closure — `fn (StreamResult $result): array` — for the
     * facts that only EXIST once the fold is over: the turn's final cost, the
     * id of the message just persisted, the plan the model settled on. It is
     * invoked after the stream drains and its return becomes the meta folded
     * into the terminal frame, on the failed path too (read `$result->failed`
     * to tell the two apart). A plain array behaves exactly as it always has.
     *
     * @param  iterable<StreamEvent>  $stream
     * @param  array<string, mixed>|Closure(StreamResult): array<string, mixed>  $meta
     */
    public function runIntoBuffer(iterable $stream, TurnBuffer $buffer, string $turnId, array|Closure $meta = []): StreamResult
    {
        $done = null;
        $error = null;

        $result = $this->runBuffered($stream, function (string $event, array $data) use ($buffer, $turnId, &$done, &$error): void {
            if ($event === 'done') {
                $done = $data;
            } elseif ($event === 'error') {
                $error = (string) ($data['message'] ?? '');
            } else {
                $buffer->append($turnId, $event, $data);
            }
        });

        $meta = $meta instanceof Closure ? $meta($result) : $meta;

        if ($error !== null) {
            $buffer->fail($turnId, $error, $meta);
        } else {
            $buffer->finish($turnId, $done ?? [], $meta);
        }

        return $result;
    }

    /**
     * Bookkeeping that happens for every event, hooked or not.
     */
    protected function collect(StreamEvent $event, StreamResult $result): void
    {
        if ($event instanceof ToolCall) {
            $result->toolCalls[] = $event->toolCall;
        } elseif ($event instanceof ToolResult) {
            $result->toolResults[] = $event->toolResult;
        } elseif ($event instanceof StreamEnd) {
            $result->usage = $result->usage?->add($event->usage) ?? $event->usage;
        }
    }

    protected function hookFor(StreamEvent $event): ?Closure
    {
        foreach ($this->hooks as [$class, $hook]) {
            if ($event instanceof $class) {
                return $hook;
            }
        }

        return null;
    }

    protected function emitText(string $text, callable $emit, StreamResult $result): void
    {
        if ($text === '') {
            return;
        }

        $result->text .= $text;

        $emit('delta', ['text' => $text]);
    }

    /**
     * Feed one delta through the pipeline; each stage may hold text back.
     */
    protected function pushText(string $text): string
    {
        foreach ($this->transformers as $transformer) {
            if ($text === '') {
                return '';
            }

            $text = $transformer->push($text);
        }

        return $text;
    }

    /**
     * Release every stage's held tail through the stages after it, in
     * order — stage i's tail is the last text stage i+1 ever receives, so
     * emitting it before flushing stage i+1 preserves the text order.
     */
    protected function flushText(): string
    {
        $out = '';
        $count = count($this->transformers);

        for ($i = 0; $i < $count; $i++) {
            $tail = $this->transformers[$i]->flush();

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tail === '') {
                    break;
                }

                $tail = $this->transformers[$j]->push($tail);
            }

            $out .= $tail;
        }

        return $out;
    }
}

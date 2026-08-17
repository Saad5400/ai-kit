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
 * model text, `error {message}` on failure, and a terminal `done {...}`
 * whose payload the caller assembles from the collected {@see StreamResult}.
 *
 * TERMINAL-EVENT CONTRACT (identical here and on the buffered path through
 * {@see TurnBuffer}): a turn ends with EXACTLY ONE terminal event —
 * `done {...}` when it completed, or `error {message}` when it did not.
 * `error` is terminal; no `done` ever follows it. A client that tears its
 * stream down on either event therefore behaves the same whether the turn
 * was streamed inline or replayed out of a buffer.
 *
 * Where the events go is the caller's business — the sink is a plain
 * `(string $event, array $data)` callable, so the same mapper drives an
 * inline SSE response (`fn ($e, $d) => $sse->emit($e, $d)`). For the
 * resumable background-job path use {@see runIntoBuffer()}, which keeps the
 * buffer the sole author of the turn's terminal event.
 *
 * Extension points:
 * - {@see transformText}: a pipeline on the text channel. Transformers may
 *   hold text back mid-stream (uqucc's streaming link guard buffers until a
 *   candidate link completes); held tails are flushed through the rest of
 *   the pipeline when the stream ends.
 * - {@see on}: intercept any stream event class and emit whatever the app's
 *   contract needs (proposal, plan, question, step, segment, ...). The hook
 *   replaces the default emission for that event; bookkeeping (tool calls /
 *   results, usage) is still collected first.
 * - {@see onReasoning}: sugar for `on(ReasoningDelta::class, ...)` —
 *   reasoning is dropped unless an app opts in.
 * - {@see beforeDone}: runs after the stream drains and the text pipeline
 *   flushes, before `done` — where post-stream events like `citations` go.
 */
class StreamEventMapper
{
    /** @var list<TextTransformer> */
    protected array $transformers = [];

    /** @var list<array{class-string<StreamEvent>, Closure}> */
    protected array $hooks = [];

    /** @var list<Closure> */
    protected array $beforeDone = [];

    protected Closure $doneUsing;

    protected Closure $errorMessage;

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
     * Handle reasoning deltas, which are otherwise dropped. The handler
     * receives `(ReasoningDelta $event, callable $emit)`.
     */
    public function onReasoning(callable $handler): static
    {
        return $this->on(
            ReasoningDelta::class,
            fn (ReasoningDelta $event, callable $emit) => $handler($event, $emit),
        );
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
     * Fold the stream into wire events on the sink. On a terminal error the
     * fold stops after emitting `error` — no `done` is emitted, mirroring
     * the apps' contract (the client treats `error` as terminal).
     *
     * @param  iterable<StreamEvent>  $stream
     * @param  callable(string, array<string, mixed>): void  $emit
     */
    public function run(iterable $stream, callable $emit): StreamResult
    {
        $result = new StreamResult;

        foreach ($stream as $event) {
            $this->collect($event, $result);

            if (($hook = $this->hookFor($event)) !== null) {
                $continue = $hook($event, $emit, $result);

                if ($event instanceof Error) {
                    $result->error = $event;

                    if ($continue !== true) {
                        $result->failed = true;

                        return $result;
                    }
                }

                continue;
            }

            if ($event instanceof TextDelta) {
                $this->emitText($this->pushText($event->delta), $emit, $result);
            } elseif ($event instanceof Error) {
                $result->failed = true;
                $result->error = $event;

                $emit('error', ['message' => ($this->errorMessage)($event)]);

                return $result;
            }
        }

        $this->emitText($this->flushText(), $emit, $result);

        foreach ($this->beforeDone as $callback) {
            $callback($result, $emit);
        }

        $emit('done', ($this->doneUsing)($result));

        return $result;
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
     * @param  iterable<StreamEvent>  $stream
     * @param  array<string, mixed>  $meta
     */
    public function runIntoBuffer(iterable $stream, TurnBuffer $buffer, string $turnId, array $meta = []): StreamResult
    {
        $done = null;
        $error = null;

        $result = $this->run($stream, function (string $event, array $data) use ($buffer, $turnId, &$done, &$error): void {
            if ($event === 'done') {
                $done = $data;
            } elseif ($event === 'error') {
                $error = (string) ($data['message'] ?? '');
            } else {
                $buffer->append($turnId, $event, $data);
            }
        });

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

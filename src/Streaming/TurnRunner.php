<?php

namespace Saad\AiKit\Streaming;

use Closure;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use IteratorAggregate;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Streaming\Events\TurnStopped;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\Events\InterruptedSpendResolved;
use Throwable;

/**
 * The DRY core of a background turn job — the machinery every app's
 * "generate a reply" job needed verbatim (extracted from catodemy's, which
 * was itself adapted from s-grade's): the kill-switch re-check, the feature
 * Context label, the acting-user guard swap, the {@see ToolProgress}
 * binding, the cancel generator, the buffer sink, the catch-all, and one
 * finally that cleans up every exit path.
 *
 * SPEND: every run opens with {@see TurnContext::beginTurn()} — a clean
 * spend collector (an inline or sync-queued turn shares its caller's
 * Context, so the worker's per-job reset does not cover it) and the turn's
 * id + `$meta`, which the usage module stamps on the `stopped`/`failed` row
 * it writes for an interrupted turn and hands to
 * {@see InterruptedSpendResolved}.
 *
 * WHAT STAYS APP-SIDE, deliberately: model/user resolution, prompt
 * assembly, metering, and — above all — THE TERMINAL EVENT. The runner holds
 * `done`/`error` back and surfaces them on the {@see TurnOutcome}; the app
 * writes its own `finish()`/`fail()`, because its completion payload
 * (credit outcome, grounding, persisted message) only exists after the
 * fold. Contrast {@see StreamEventMapper::runIntoBuffer()}, which writes
 * the terminal for apps that don't need any of this.
 *
 * ORDERING GUARANTEES:
 *  - the kill-switch is re-checked HERE, where the spend would happen — a
 *    switch engaged mid-incident exists precisely to stop a queued backlog
 *    — and the `$stream` closure is only invoked after every guard passes,
 *    so a killed turn never opens a provider connection;
 *  - the guard swap is scoped to the fold and restored in the finally
 *    (Octane-safe: a worker never leaks the acting user into the next job);
 *  - {@see ToolProgress} is bound before the fold and unbound in the same
 *    finally, so a tool's progress seam exists exactly while its turn does.
 *
 * CANCELLATION: the `untilCancelled` generator wraps the stream in front
 * of the mapper (yield first, then poll — a stop pressed before anything
 * streamed still lands on the first event). The poll is throttled to one
 * cache read per second and each poll also touches the buffer's heartbeat.
 * A cancelled stream simply ends: the fold takes its normal exit and the
 * outcome is `cancelled` with the partial text — a stop is a completed
 * short turn, not an error. Stops landing INSIDE a tool are ToolProgress's
 * job, not this generator's.
 *
 * What a stop leaves IN STORAGE: laravel/ai 1.0 has no cancel of its own,
 * and a stream the consumer walks away from runs neither `then()` nor
 * `catch()` — the turn, its user message and every tool it already ran
 * would leave no row. So the generator does not just walk away: it throws
 * a {@see TurnCancelledException} into the provider stream at the point it
 * stopped, which is laravel/ai's own failure path — RememberConversation
 * stores the turn as a `failed` row with its completed steps (plus the
 * interrupted step's partial text, {@see InterruptedTurns}) and
 * `meta.error` = TurnCancelledException::MESSAGE, the marker an app reads
 * to show "stopped" rather than "failed". The exception is caught right
 * back here; the wire and the outcome are unchanged. (Behind a
 * multi-provider failover list the throw lands in the outer failover
 * stream, which has no conversation hooks; that stop is not stored.)
 *
 * FAILURES keep what the turn produced: `result` on a failed outcome is
 * the partial fold — the text and tools before a provider error in the
 * stream, and before a throw (a 502 raised as an exception) alike. The
 * outcome's `failureCode` carries the kit's {@see ErrorCode}.
 *
 * THE SINK routes non-terminal events to {@see TurnBuffer::append()},
 * except `tool` frames carrying `progress`, which go to
 * {@see TurnBuffer::upsert()} keyed by call id so a long loop's hundreds
 * of updates stay one log entry. Progress frames are re-stamped with the
 * tool's `name` (remembered from its own `running` frame) before they are
 * written: upsert REPLACES that frame, and a client resuming from cursor 0
 * would otherwise replay a chip with no name.
 *
 * The `$stream` closure returns the provider stream (e.g.
 * `fn () => $agent->stream($prompt)`). An app that needs the response
 * object afterwards captures it by reference:
 *
 *     stream: function () use ($agent, $prompt, &$response) {
 *         return $response = $agent->stream($prompt);
 *     },
 */
class TurnRunner
{
    public function __construct(protected ?KillSwitch $killSwitch = null) {}

    /**
     * Run one turn's fold into the buffer, returning the outcome the app
     * writes its terminal event from. `$failMessage` resolves the
     * app-facing failure line — `fn (?Throwable $e): string` (null when
     * the failure was a wire `error` event that carried no message);
     * without it the exception's own message is used, mirroring the
     * mapper's raw default — public apps should always pass a localized
     * resolver. `$meta` (queue-serialisable) rides along to
     * {@see InterruptedSpendResolved} — put what the app needs to find the
     * payer there.
     *
     * @param  Closure(): iterable<StreamEvent>  $stream
     * @param  array<string, mixed>  $meta
     */
    public function run(
        string $turnId,
        Closure $stream,
        StreamEventMapper $mapper,
        TurnBuffer $buffer,
        ?string $feature = null,
        ?Authenticatable $actingAs = null,
        ?Closure $failMessage = null,
        array $meta = [],
    ): TurnOutcome {
        $resolveFailure = fn (?Throwable $e): string => $failMessage !== null
            ? (string) $failMessage($e)
            : (string) ($e?->getMessage() ?? '');

        $guard = Auth::guard();
        $previousUser = null;
        $swapped = false;
        $result = null;
        $provider = null;
        $opened = false;

        // ONE outer try/finally owns every cleanup from here on — the
        // ToolProgress unbind and the guard restore run on EVERY exit
        // (normal, cancelled, kill-switched, thrown), so a recycled
        // Octane/queue worker can never carry this turn's binding or acting
        // user into the next turn. The unbind is unconditional on purpose:
        // it also sweeps up anything a crashed previous turn left behind.
        try {
            // The HTTP entry point already guarded this turn, but that was
            // before it was queued — possibly long before. Re-check where
            // the model calls actually happen; nothing has streamed yet and
            // no provider connection is opened.
            if ($this->killSwitch?->engaged($feature)) {
                return TurnOutcome::failed(new StreamResult, (string) __('ai-kit::safety.killed'), code: ErrorCode::KILLED);
            }

            // A clean spend collector and the turn's id + app metadata, which
            // the usage module stamps on an interrupted turn's row and hands
            // to InterruptedSpendResolved; unbound in the finally.
            TurnContext::beginTurn($turnId, $meta);
            $opened = true;

            // Label every model call this turn makes so the usage rows are
            // attributable; inherited by any pre-pass the app runs inside
            // the stream closure as well as the streamed turn itself.
            if ($feature !== null) {
                Context::add((string) config('ai-kit.usage.feature_context_key', 'ai-kit.feature'), $feature);
            }

            // Acting-user scoping: authenticate the acting user for the
            // WHOLE fold so every model scope / policy / audit causer that
            // reads auth()->user() is correct; the finally restores the
            // prior guard state (Octane-safe).
            if ($actingAs !== null) {
                $previousUser = $guard->hasUser() ? $guard->user() : null;
                $guard->setUser($actingAs);
                $swapped = true;
            }

            $done = null;
            $error = null;
            $errorCode = null;
            $cancelled = false;

            /** @var array<string, string> $toolNames */
            $toolNames = [];

            $sink = function (string $event, array $data) use ($buffer, $turnId, &$done, &$error, &$errorCode, &$toolNames): void {
                if ($event === 'done') {
                    $done = $data;

                    return;
                }

                if ($event === 'error') {
                    $error = (string) ($data['message'] ?? '');
                    $errorCode = is_string($data['code'] ?? null) ? $data['code'] : null;

                    return;
                }

                if ($event === 'tool' && is_string($data['id'] ?? null)) {
                    if (is_string($data['name'] ?? null)) {
                        $toolNames[$data['id']] = $data['name'];
                    }

                    if (isset($data['progress'])) {
                        if (! isset($data['name']) && isset($toolNames[$data['id']])) {
                            $data['name'] = $toolNames[$data['id']];
                        }

                        $buffer->upsert($turnId, 'tool', $data);

                        return;
                    }
                }

                $buffer->append($turnId, $event, $data);
            };

            ToolProgress::bind($turnId, $sink, $buffer);

            // Collected into from outside the fold so a THROW mid-turn still
            // leaves the text and tools the turn produced on the outcome.
            $result = new StreamResult;

            $provider = $stream();

            $mapper->runBuffered(
                $this->untilCancelled($provider, $buffer, $turnId, $cancelled),
                $sink,
                $result,
            );

            if ($result->failed) {
                return TurnOutcome::failed(
                    $result,
                    $error !== null && $error !== '' ? $error : $resolveFailure(null),
                    code: $errorCode ?? ($result->error !== null ? ErrorCode::forError($result->error) : ErrorCode::STREAM_ERROR),
                    conversationId: StoredConversation::idOf($provider),
                );
            }

            // A stop is stored as a failed row and never runs the vendor's
            // then(): name its conversation too.
            return TurnOutcome::completed($result, $done, $cancelled, $cancelled ? StoredConversation::idOf($provider) : null);
        } catch (Throwable $e) {
            return TurnOutcome::failed($result ?? new StreamResult, $resolveFailure($e), $e, ErrorCode::forException($e), StoredConversation::idOf($provider));
        } finally {
            ToolProgress::unbind();

            if ($opened) {
                TurnContext::endTurn();
            }

            if ($swapped) {
                $previousUser !== null ? $guard->setUser($previousUser) : Auth::forgetGuards();
            }
        }
    }

    /**
     * The provider stream, cut short the moment the user's stop lands.
     *
     * Yield FIRST, poll after — with the 0.0 seed the first event still
     * checks, so a stop pressed before anything streamed lands immediately.
     * The poll is throttled to one cache read per second (never a per-token
     * hammer), and every poll also touches the buffer's heartbeat, so a
     * turn that streams without appending anything visible still reads as
     * alive to the stale watchdog. A stop settles the provider stream
     * through {@see interrupt()} before the generator ends.
     *
     * @param  iterable<StreamEvent>  $stream
     * @return Generator<StreamEvent>
     */
    protected function untilCancelled(iterable $stream, TurnBuffer $buffer, string $turnId, bool &$cancelled): Generator
    {
        $lastCheck = 0.0;

        // Iterated by hand (rather than through the aggregate) so a stop can
        // throw into the very generator the vendor is suspended in.
        $events = $stream instanceof IteratorAggregate ? $stream->getIterator() : $stream;

        // Once the run has reached its end (the final StreamEnd) or its
        // pause (a ToolApprovalRequest — the approval card is already on
        // the client), a stop is too late to mean anything: the vendor is
        // about to store the turn as completed or paused, and throwing in
        // now would store it as a stopped `failed` row instead — a finished
        // answer without its usage, or a pause the client already shows.
        $ending = false;

        foreach ($events as $event) {
            try {
                yield $event;
            } catch (Throwable $e) {
                // Thrown in by the fold (a stream yielding past its settled
                // error): forward it to the vendor generator, so its failure
                // path runs, then let it propagate back to the fold.
                if ($events instanceof Generator && $events->valid()) {
                    $events->throw($e);
                }

                throw $e;
            }

            if ($event instanceof StreamEnd || $event instanceof ToolApprovalRequest) {
                $ending = true;
            }

            if ($ending || $this->now() - $lastCheck < 1000.0) {
                continue;
            }

            $lastCheck = $this->now();

            $buffer->touch($turnId);

            if ($buffer->isCancelled($turnId)) {
                $cancelled = true;

                $this->interrupt($stream, $events, $event);

                return;
            }
        }
    }

    /**
     * Settle a stopped provider stream through laravel/ai's failure path so
     * the turn is stored (see the class doc), instead of abandoning it.
     * The interrupted step's partial text is recorded first, the
     * {@see TurnCancelledException} is thrown in at the vendor's yield and
     * comes straight back out; anything else the vendor's persistence
     * throws on the way is reported, never allowed to turn a stop into a
     * failure.
     *
     * @param  iterable<StreamEvent>  $stream
     * @param  iterable<StreamEvent>  $events
     */
    protected function interrupt(iterable $stream, iterable $events, StreamEvent $last): void
    {
        if (! $events instanceof Generator || ! $events->valid()) {
            return;
        }

        $invocationId = $stream instanceof StreamableAgentResponse ? $stream->invocationId : $last->invocationId;

        if ($invocationId !== null && app()->bound(InterruptedTurns::class)) {
            app(InterruptedTurns::class)->sealTracked($invocationId);
        }

        try {
            $events->throw(new TurnCancelledException);
        } catch (TurnCancelledException) {
            // The vendor's catch ran and rethrew it — the expected exit.
        } catch (Throwable $e) {
            report($e);
        }

        // laravel/ai reports no AgentFailed for it (the throw landed at the
        // response's iterator, outside its loop): announce the stop, so the
        // usage module records the stopped turn's spend.
        if ($invocationId !== null) {
            rescue(fn () => event(new TurnStopped($invocationId)));
        }
    }

    /**
     * Milliseconds on Laravel's clock, so tests drive the poll throttle
     * with `Carbon::setTestNow()` instead of sleeping.
     */
    protected function now(): float
    {
        return Carbon::now()->getPreciseTimestamp(3);
    }
}

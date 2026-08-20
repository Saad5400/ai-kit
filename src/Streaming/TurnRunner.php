<?php

namespace Saad\AiKit\Streaming;

use Closure;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Saad\AiKit\Safety\KillSwitch;
use Throwable;

/**
 * The DRY core of a background turn job — the machinery every app's
 * "generate a reply" job needed verbatim (extracted from catodemy's, which
 * was itself adapted from s-grade's): the kill-switch re-check, the feature
 * Context label, the acting-user guard swap, the {@see ToolProgress}
 * binding, the cancel generator, the buffer sink, the catch-all, and one
 * finally that cleans up every exit path.
 *
 * WHAT STAYS APP-SIDE, deliberately: model/user resolution, prompt
 * assembly, metering, per-turn spend reset (catodemy's TurnProviderSpend is
 * an app-level accumulator; the kit's SpendCollector is a per-call
 * scratchpad the usage module drains, so there is no kit-level reset to
 * own here), and — above all — THE TERMINAL EVENT. The runner holds
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
     * resolver.
     *
     * @param  Closure(): iterable<StreamEvent>  $stream
     */
    public function run(
        string $turnId,
        Closure $stream,
        StreamEventMapper $mapper,
        TurnBuffer $buffer,
        ?string $feature = null,
        ?Authenticatable $actingAs = null,
        ?Closure $failMessage = null,
    ): TurnOutcome {
        $resolveFailure = fn (?Throwable $e): string => $failMessage !== null
            ? (string) $failMessage($e)
            : (string) ($e?->getMessage() ?? '');

        // The HTTP entry point already guarded this turn, but that was
        // before it was queued — possibly long before. Re-check where the
        // model calls actually happen; nothing has streamed yet and no
        // provider connection is opened.
        if ($this->killSwitch?->engaged($feature)) {
            return TurnOutcome::failed(new StreamResult, (string) __('ai-kit::safety.killed'));
        }

        // Label every model call this turn makes so the usage rows are
        // attributable; inherited by any pre-pass the app runs inside the
        // stream closure as well as the streamed turn itself.
        if ($feature !== null) {
            Context::add((string) config('ai-kit.usage.feature_context_key', 'ai-kit.feature'), $feature);
        }

        // Acting-user scoping: authenticate the acting user for the WHOLE
        // fold so every model scope / policy / audit causer that reads
        // auth()->user() is correct, then restore the prior guard state in
        // the finally (Octane-safe).
        $guard = Auth::guard();
        $previousUser = null;

        if ($actingAs !== null) {
            $previousUser = $guard->hasUser() ? $guard->user() : null;
            $guard->setUser($actingAs);
        }

        $done = null;
        $error = null;
        $cancelled = false;

        /** @var array<string, string> $toolNames */
        $toolNames = [];

        $sink = function (string $event, array $data) use ($buffer, $turnId, &$done, &$error, &$toolNames): void {
            if ($event === 'done') {
                $done = $data;

                return;
            }

            if ($event === 'error') {
                $error = (string) ($data['message'] ?? '');

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

        try {
            $result = $mapper->runBuffered(
                $this->untilCancelled($stream(), $buffer, $turnId, $cancelled),
                $sink,
            );

            if ($result->failed) {
                return TurnOutcome::failed(
                    $result,
                    $error !== null && $error !== '' ? $error : $resolveFailure(null),
                );
            }

            return TurnOutcome::completed($result, $done, $cancelled);
        } catch (Throwable $e) {
            return TurnOutcome::failed(new StreamResult, $resolveFailure($e), $e);
        } finally {
            ToolProgress::unbind();

            if ($actingAs !== null) {
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
     * alive to the stale watchdog.
     *
     * @param  iterable<StreamEvent>  $stream
     * @return Generator<StreamEvent>
     */
    protected function untilCancelled(iterable $stream, TurnBuffer $buffer, string $turnId, bool &$cancelled): Generator
    {
        $lastCheck = 0.0;

        foreach ($stream as $event) {
            yield $event;

            if ($this->now() - $lastCheck < 1000.0) {
                continue;
            }

            $lastCheck = $this->now();

            $buffer->touch($turnId);

            if ($buffer->isCancelled($turnId)) {
                $cancelled = true;

                return;
            }
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

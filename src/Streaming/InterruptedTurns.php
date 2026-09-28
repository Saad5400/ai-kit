<?php

namespace Saad\AiKit\Streaming;

use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Tops up what laravel/ai 1.0 records for a turn that did not finish, so a
 * failed or stopped turn is ALWAYS stored and shown in history (owner
 * ruling 2026-09-28) — with the text the user already watched stream.
 *
 * Stock 1.0 stores a dead run through RememberConversation's catch, from
 * the steps the run COMPLETED (RunContext::recordedResponse()). Two things
 * fall through that:
 *
 *  - a run that died in its FIRST step has no completed step, and stock
 *    then stores nothing at all — not the assistant row, and not the user's
 *    own message either, which silently vanishes from the conversation;
 *  - the step that died mid-stream is dropped whole, so the row keeps none
 *    of the partial text that was on screen when the error landed.
 *
 * {@see seal()} closes both gaps from one place, BEFORE the vendor's catch
 * reads the run context: it records one more step on it — the interrupted
 * step's partial text and reasoning (no tool calls: none of them ran), or,
 * when the run has nothing at all, an empty step, which makes stock store
 * the user row and a `failed` assistant row. Stock replays a failed row
 * safely: a blank step replays as nothing, a partial one as the assistant
 * text the user saw.
 *
 * The partial text is what the {@see StreamEventMapper} fold saw (raw
 * deltas, before the text pipeline — the same text 1.0 stores for a
 * completed step), tracked per invocation from the vendor's
 * `StreamingAgent` event until the run is streamed or sealed. 1.0 opens
 * every step with exactly one `StreamStart`, so "a step started that the
 * run context never recorded" is how the fold's last stretch of text is
 * known to belong to the step that died — and not to a completed step
 * whose tools then failed, or a step whose request never connected.
 *
 * Seals come from the vendor's `StepFailed` event (a provider error in the
 * stream or a throw — dispatched inside the loop, before the exception
 * reaches RememberConversation's catch, on the prompted and the streamed
 * path alike), from `AgentFailed` as the fallback for a failure outside a
 * step, and from {@see TurnRunner} when the user stops the turn. A run with
 * no mapper in front of it (a plain `prompt()`) still gets the empty step,
 * so its user row is kept; it just has no partial text to add. A seal for
 * an attempt a failover then retries lands on the abandoned attempt's
 * context and is never stored.
 *
 * Scoped per request / job; entries are forgotten when the run streams to
 * completion or is sealed.
 */
class InterruptedTurns
{
    /** @var array<string, array{prompt: AgentPrompt, starts: int, text: string, reasoning: string}> */
    protected array $turns = [];

    public function __construct(protected bool $enabled = true) {}

    /**
     * Start tracking a run (from the vendor's `PromptingAgent` / `StreamingAgent`).
     */
    public function track(string $invocationId, AgentPrompt $prompt): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->turns[$invocationId] = ['prompt' => $prompt, 'starts' => 0, 'text' => '', 'reasoning' => ''];
    }

    /**
     * Fold one stream event into its run's partial step. Events of runs
     * that are not tracked (a sub-agent's, a faked stream's) are ignored.
     */
    public function observe(StreamEvent $event): void
    {
        $id = $event->invocationId;

        if ($id === null || ! isset($this->turns[$id])) {
            return;
        }

        if ($event instanceof StreamStart) {
            $this->turns[$id]['starts']++;
            $this->turns[$id]['text'] = '';
            $this->turns[$id]['reasoning'] = '';
        } elseif ($event instanceof TextDelta) {
            $this->turns[$id]['text'] .= $event->delta;
        } elseif ($event instanceof ReasoningDelta) {
            $this->turns[$id]['reasoning'] .= $event->delta;
        }
    }

    /**
     * Seal a TRACKED run by its invocation id — the stop path, where only
     * the stream (not the prompt) is in hand.
     */
    public function sealTracked(string $invocationId): void
    {
        if (isset($this->turns[$invocationId])) {
            $this->seal($this->turns[$invocationId]['prompt'], $invocationId);
        }
    }

    /**
     * Record the interrupted step on the run's context, ahead of the
     * vendor's failed-turn persistence. Idempotent: the entry is forgotten
     * here, so a second seal for the same run adds nothing.
     */
    public function seal(AgentPrompt $prompt, string $invocationId): void
    {
        $turn = $this->turns[$invocationId] ?? null;

        $this->forget($invocationId);

        if (! $this->enabled || ($context = $prompt->runContext()) === null) {
            return;
        }

        $recorded = $context->recordedResponse()->steps->count();

        // A step started that the context never recorded: the text since
        // its StreamStart is the interrupted step's.
        $interrupted = $turn !== null && $turn['starts'] > $recorded;

        $text = $interrupted ? $turn['text'] : '';
        $reasoning = $interrupted ? $turn['reasoning'] : '';

        if ($text === '' && $reasoning === '') {
            // Stock already fails the paused row a dead resume answers, and a
            // run with completed steps is already kept; only an otherwise
            // empty run needs the placeholder step.
            if ($recorded > 0 || $prompt->hasApprovalDecisions()) {
                return;
            }
        }

        $context->recordStep(new Step(
            text: $text,
            toolCalls: [],
            toolResults: [],
            finishReason: FinishReason::Error,
            usage: new TextUsage,
            meta: new Meta($context->provider->name(), $context->model),
            reasoning: $reasoning,
            replayBlocks: [],
        ));
    }

    public function forget(string $invocationId): void
    {
        unset($this->turns[$invocationId]);
    }

    /**
     * Whether a run is still being tracked — for tests and diagnostics.
     */
    public function tracking(string $invocationId): bool
    {
        return isset($this->turns[$invocationId]);
    }
}

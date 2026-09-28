<?php

namespace Saad\AiKit\Usage\Listeners;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\TextUsage;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Streaming\Events\TurnStopped;
use Saad\AiKit\Streaming\TurnCancelledException;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\ActiveRuns;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Saad\AiKit\Usage\InterruptedSpend;
use Saad\AiKit\Usage\TraceLogger;
use Saad\AiKit\Usage\UsageEvent;
use Throwable;

/**
 * Writes the usage row of a turn that did NOT complete — the half
 * {@see RecordTurnUsage} cannot see, because laravel/ai 1.0 fires
 * AgentPrompted/AgentStreamed only for completed (or paused) turns.
 *
 * laravel/ai dispatches `AgentFailed` for every terminal failure of a run,
 * prompted or streamed — but not for a stop: TurnRunner's
 * {@see TurnCancelledException} lands at the response's iterator, outside
 * the loop that reports failures, so TurnRunner announces it as
 * {@see TurnStopped} (the run's prompt comes from {@see ActiveRuns}). So
 * ONE row per interrupted turn, status `stopped` (the stop) or
 * `failed` (anything else), carrying what the spend collector holds: the
 * exact cost and generation ids of every step the turn completed, plus an
 * interrupted step whose usage frame arrived before the cut. Tokens are
 * the completed steps' (the run context's recorded response). Then
 * {@see TurnUsageRecorded} fires with the interruption named — the budget
 * listener counts both kinds; whether to DEBIT is the app's ruling
 * (stopped → debit, failed → record only).
 *
 * Generations cut off before their cost arrived go to
 * {@see InterruptedSpend}, which prices them later and reports the delta
 * as its own event.
 *
 * Idempotent: a run that already has a turn row (a completion that won the
 * race against a stop, or a replayed event) gets no second row. A failover
 * attempt the caller will retry never reaches here (laravel/ai holds its
 * AgentFailed back). Metering never breaks a turn: failures are reported
 * and swallowed.
 */
class RecordInterruptedUsage
{
    public function __construct(
        protected SpendCollector $spend,
        protected TraceLogger $trace,
        protected InterruptedSpend $interrupted,
        protected ActiveRuns $runs,
    ) {}

    public function handle(AgentFailed|TurnStopped $event): void
    {
        rescue(function () use ($event) {
            if ($event instanceof AgentFailed) {
                $this->record($event->invocationId, $event->prompt, $event->exception);
            } elseif (($prompt = $this->runs->prompt($event->invocationId)) !== null) {
                $this->record($event->invocationId, $prompt, new TurnCancelledException);
            }
        });
    }

    protected function record(string $invocationId, AgentPrompt $prompt, Throwable $exception): void
    {
        $this->runs->forget($invocationId);

        $exists = UsageEvent::query()
            ->where('invocation_id', $invocationId)
            ->whereIn('status', ['ok', 'paused', TurnUsageRecorded::STOPPED, TurnUsageRecorded::FAILED])
            ->exists();

        if ($exists) {
            return;
        }

        $interruption = $exception instanceof TurnCancelledException
            ? TurnUsageRecorded::STOPPED
            : TurnUsageRecorded::FAILED;

        // With `drain_spend` off the app drains the collector itself, so it
        // still holds spend earlier usage rows already counted: the row then
        // carries no spend of its own (and prices nothing) rather than
        // counting it twice.
        $drains = (bool) config('ai-kit.usage.drain_spend', true);

        $cost = $drains ? $this->spend->totalCost() : 0.0;
        $generationIds = $drains ? $this->spend->generationIds() : [];
        $pending = $drains ? $this->spend->pendingGenerationIds() : [];

        // The only trace a failed run leaves of which path it took: a
        // streamed step (priced or pending), or a stop — only streams stop.
        $streamed = $this->spend->generationIds(streamed: true) !== []
            || $pending !== []
            || $interruption === TurnUsageRecorded::STOPPED;

        if ($drains) {
            $this->spend->flush();
        }

        [$durationMs, $ttftMs] = TurnContext::consume($invocationId);
        $flags = TurnContext::consumeFlags();
        $turnId = TurnContext::turnId();

        $agent = $prompt->agent;
        $context = $prompt->runContext();
        $usage = $context?->recordedResponse()->usage ?? new TextUsage;

        $participant = method_exists($agent, 'conversationParticipant') ? $agent->conversationParticipant() : null;
        $conversationId = method_exists($agent, 'currentConversation') ? $agent->currentConversation() : null;

        $usageEvent = UsageEvent::create([
            'invocation_id' => $invocationId,
            'conversation_id' => $conversationId,
            'participant_type' => $participant !== null ? Conversation::participantType($participant) : null,
            'participant_id' => $participant !== null ? (string) Conversation::participantKey($participant) : null,
            'agent' => $agent::class,
            'feature' => Context::get(config('ai-kit.usage.feature_context_key', 'ai-kit.feature')),
            'provider' => $context?->provider->name() ?? $this->providerName($prompt),
            'model' => $context?->model ?? $prompt->model,
            'streamed' => $streamed,
            'prompt_tokens' => $usage->inputTokens,
            'completion_tokens' => $usage->outputTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens ?? 0,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens ?? 0,
            'reasoning_tokens' => $usage->reasoningTokens ?? 0,
            'cost_usd' => $cost > 0 ? $cost : null,
            'cost_source' => $cost > 0 ? 'provider' : null,
            'generation_ids' => $generationIds !== [] ? $generationIds : null,
            'duration_ms' => $durationMs,
            'ttft_ms' => $ttftMs,
            'status' => $interruption,
            'error' => $interruption === TurnUsageRecorded::FAILED
                ? Str::limit($exception::class.': '.$exception->getMessage(), 500)
                : null,
            'context' => array_filter([
                ...$flags,
                'turn_id' => $turnId,
                'pending_generation_ids' => $pending !== [] ? $pending : null,
            ], fn ($value) => $value !== null) ?: null,
            'created_at' => now(),
        ]);

        $this->trace->turn($usageEvent);

        event(new TurnUsageRecorded($usageEvent, $interruption));

        $this->interrupted->track($usageEvent, $pending, $turnId, TurnContext::turnMeta());
    }

    protected function providerName(AgentPrompt $prompt): string
    {
        try {
            return $prompt->provider()->name();
        } catch (Throwable) {
            return 'unknown';
        }
    }
}

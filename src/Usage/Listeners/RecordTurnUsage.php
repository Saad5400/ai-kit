<?php

namespace Saad\AiKit\Usage\Listeners;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Models\Conversation;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Saad\AiKit\Usage\TraceLogger;
use Saad\AiKit\Usage\UsageEvent;

/**
 * Writes the canonical usage row when a turn completes.
 *
 * Cost comes from ONE place: the exact `usage.cost` OpenRouter reports for
 * the generation, captured by the spend collector. There is no price-table
 * fallback (DECISIONS.md #26c) — the provider already tells us what the turn
 * cost, and a hand-maintained per-million table can only ever drift away from
 * that truth and then quietly bill the difference. A turn whose cost the
 * provider did not report records `cost_usd` NULL with no `cost_source`, and
 * downstream billing waives it rather than guessing.
 *
 * Metering must never break a turn: failures are reported and swallowed.
 */
class RecordTurnUsage
{
    public function __construct(
        protected SpendCollector $spend,
        protected TraceLogger $trace,
    ) {}

    public function handle(AgentPrompted $event): void
    {
        rescue(fn () => $this->record($event));
    }

    protected function record(AgentPrompted $event): void
    {
        $response = $event->response;
        $usage = $response->usage;
        $model = $response->meta->model ?? $event->prompt->model;
        $participant = $response->conversationUser;

        [$cost, $generationIds] = $this->collectSpend();
        [$durationMs, $ttftMs] = TurnContext::consume($event->invocationId);

        $costSource = $cost !== null ? 'provider' : null;

        $usageEvent = UsageEvent::create([
            'invocation_id' => $event->invocationId,
            'conversation_id' => $response->conversationId,
            'participant_type' => $participant !== null ? Conversation::participantType($participant) : null,
            'participant_id' => $participant !== null ? (string) Conversation::participantKey($participant) : null,
            'agent' => $event->prompt->agent::class,
            'feature' => Context::get(config('ai-kit.usage.feature_context_key', 'ai-kit.feature')),
            'provider' => $response->meta->provider ?? $event->prompt->provider()->name(),
            'model' => $model,
            'streamed' => $event instanceof AgentStreamed,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'cost_usd' => $cost,
            'cost_source' => $costSource,
            'generation_ids' => $generationIds !== [] ? $generationIds : null,
            'duration_ms' => $durationMs,
            'ttft_ms' => $ttftMs,
            'status' => $response->hasPendingApprovals() ? 'paused' : 'ok',
            'created_at' => now(),
        ]);

        $this->trace->turn($usageEvent);

        event(new TurnUsageRecorded($usageEvent));
    }

    /**
     * Read the collector's cost and generation ids, clearing it unless the
     * app still drains it itself (dual-write transition).
     *
     * @return array{0: ?float, 1: list<string>}
     */
    protected function collectSpend(): array
    {
        $cost = $this->spend->totalCost();
        $generationIds = $this->spend->generationIds();

        if (config('ai-kit.usage.drain_spend', true)) {
            $this->spend->flush();
        }

        return [$cost > 0 ? $cost : null, $generationIds];
    }
}

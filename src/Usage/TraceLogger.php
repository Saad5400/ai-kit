<?php

namespace Saad\AiKit\Usage;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Emits one structured log record per turn (and one per failover attempt)
 * with OTel GenAI semantic-convention attribute names, so traces survive a
 * later move to a real telemetry backend without renaming anything.
 * ai-kit-specific attributes live under the `ai_kit.` namespace.
 */
class TraceLogger
{
    public function turn(UsageEvent $usage): void
    {
        $this->logger()?->info('gen_ai.turn', array_filter([
            'gen_ai.system' => $usage->provider,
            'gen_ai.response.model' => $usage->model,
            'gen_ai.conversation.id' => $usage->conversation_id,
            'gen_ai.agent.name' => $usage->agent,
            'gen_ai.usage.input_tokens' => $usage->prompt_tokens,
            'gen_ai.usage.output_tokens' => $usage->completion_tokens,
            'ai_kit.invocation_id' => $usage->invocation_id,
            'ai_kit.feature' => $usage->feature,
            'ai_kit.streamed' => $usage->streamed,
            'ai_kit.cost_usd' => $usage->cost_usd,
            'ai_kit.duration_ms' => $usage->duration_ms,
            'ai_kit.ttft_ms' => $usage->ttft_ms,
            'ai_kit.status' => $usage->status,
        ], fn ($value) => $value !== null));
    }

    public function failover(UsageEvent $usage): void
    {
        $this->logger()?->warning('gen_ai.failover', array_filter([
            'gen_ai.system' => $usage->provider,
            'gen_ai.response.model' => $usage->model,
            'gen_ai.agent.name' => $usage->agent,
            'ai_kit.invocation_id' => $usage->invocation_id,
            'ai_kit.error' => $usage->error,
        ], fn ($value) => $value !== null));
    }

    protected function logger(): ?LoggerInterface
    {
        if (! config('ai-kit.usage.trace.enabled', true)) {
            return null;
        }

        return Log::channel(config('ai-kit.usage.trace.channel'));
    }
}

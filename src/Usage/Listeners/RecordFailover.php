<?php

namespace Saad\AiKit\Usage\Listeners;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\TraceLogger;
use Saad\AiKit\Usage\UsageEvent;

/**
 * Writes a `failed_over` row per abandoned provider/model attempt — the raw
 * material for the turn-failure-rate metric and for judging whether an app's
 * declared provider failover actually earns its keep. Model-level fallbacks
 * are invisible here by design — OpenRouter resolves those inside the one
 * request, and this listener only sees laravel/ai giving up on a provider.
 * The event doesn't carry the
 * invocation id, so it is read from the turn context. These rows never fire
 * TurnUsageRecorded: nothing billable happened.
 */
class RecordFailover
{
    public function __construct(protected TraceLogger $trace) {}

    public function handle(AgentFailedOver $event): void
    {
        rescue(function () use ($event) {
            $usageEvent = UsageEvent::create([
                'invocation_id' => Context::get(TurnContext::CURRENT_INVOCATION_KEY) ?? Str::uuid7(),
                'agent' => $event->agent::class,
                'feature' => Context::get(config('ai-kit.usage.feature_context_key', 'ai-kit.feature')),
                'provider' => $event->provider->name(),
                'model' => $event->model,
                'status' => 'failed_over',
                'error' => Str::limit($event->exception::class.': '.$event->exception->getMessage(), 500),
                'created_at' => now(),
            ]);

            $this->trace->failover($usageEvent);
        });
    }
}

<?php

namespace Saad\AiKit\Usage;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Support\LoadsKitMigrations;
use Saad\AiKit\Usage\Listeners\RecordFailover;
use Saad\AiKit\Usage\Listeners\RecordTurnUsage;
use Saad\AiKit\Usage\Listeners\StampTurnStart;

class UsageServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

    public function register(): void
    {
        $this->app->singleton(TurnSpend::class);
    }

    public function boot(): void
    {
        // Reaching boot() at all is the module gate: the root provider only
        // registers this provider when `ai-kit.modules.usage` is true.
        $this->loadKitMigrations(__DIR__.'/../../database/migrations/usage');

        // AgentStreamed extends AgentPrompted, but Laravel only fans events
        // out to interface listeners, never parent-class ones — both need
        // explicit registration, and nothing double-fires.
        Event::listen([PromptingAgent::class, StreamingAgent::class], StampTurnStart::class);
        Event::listen([AgentPrompted::class, AgentStreamed::class], RecordTurnUsage::class);

        if ($this->app['config']->get('ai-kit.usage.record_failovers', true)) {
            Event::listen(AgentFailedOver::class, RecordFailover::class);
        }
    }
}

<?php

namespace Saad\AiKit\Usage;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Usage\Listeners\RecordFailover;
use Saad\AiKit\Usage\Listeners\RecordTurnUsage;
use Saad\AiKit\Usage\Listeners\StampTurnStart;

class UsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TurnSpend::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'ai-kit-migrations');

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

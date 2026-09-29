<?php

namespace Saad\AiKit\Usage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Gateway\GenerationCostResolver;
use Saad\AiKit\Streaming\Events\TurnStopped;
use Saad\AiKit\Support\LoadsKitMigrations;
use Saad\AiKit\Usage\Listeners\RecordFailover;
use Saad\AiKit\Usage\Listeners\RecordInterruptedUsage;
use Saad\AiKit\Usage\Listeners\RecordTurnUsage;
use Saad\AiKit\Usage\Listeners\StampTurnStart;

class UsageServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

    public function register(): void
    {
        $this->app->singleton(TurnSpend::class);
        $this->app->scoped(ActiveRuns::class);

        $this->app->bindIf(GenerationCostResolver::class, function (Application $app) {
            $config = $app['config']->get('ai-kit.spend', []);
            $name = (string) ($config['provider'] ?? 'openrouter');
            $provider = $app['config']->get('ai.providers.'.$name, []);

            // The key is sent to openrouter.ai: never another provider's.
            $driver = $provider['driver'] ?? null;
            $misconfigured = $driver === 'openrouter'
                ? null
                : "ai-kit.spend.provider [{$name}] is not an OpenRouter provider (driver: ".($driver ?? 'none').')';

            return new GenerationCostResolver(
                $app->make(HttpFactory::class),
                $misconfigured === null ? ($provider['key'] ?? null) : null,
                $provider['url'] ?? 'https://openrouter.ai/api/v1',
                windowSeconds: (float) ($config['sync_window_seconds'] ?? 0),
                backoffMs: array_values(array_map('intval', $config['sync_backoff_ms'] ?? [500, 1000, 1500])),
                requestTimeoutSeconds: (int) ($config['request_timeout_seconds'] ?? 3),
                misconfigured: $misconfigured,
            );
        });

        $this->app->bind(InterruptedSpend::class, fn (Application $app) => new InterruptedSpend(
            $app->make(GenerationCostResolver::class),
            $app,
            $app['cache']->store(
                $app['config']->get('ai-kit.spend.cache_store') ?? $app['config']->get('ai-kit.safety.cache_store'),
            ),
            $app['config']->get('ai-kit.spend', []),
        ));
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

        // A stopped or failed turn: one `stopped` / `failed` row, and its
        // cut-off generations priced later (InterruptedSpendResolved).
        if ($this->app['config']->get('ai-kit.usage.record_interrupted', true)) {
            Event::listen([AgentFailed::class, TurnStopped::class], RecordInterruptedUsage::class);
        }

        if ($this->app['config']->get('ai-kit.usage.record_failovers', true)) {
            Event::listen(AgentFailedOver::class, RecordFailover::class);
        }
    }
}

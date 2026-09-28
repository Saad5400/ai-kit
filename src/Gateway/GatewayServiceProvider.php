<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenRouterProvider;
use Saad\AiKit\Catalog\ModelRouting;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContextSpendCollector::class, fn (Application $app) => new ContextSpendCollector(
            $app['config']->get('ai-kit.gateway.spend_context_prefix', 'ai'),
        ));

        $this->app->singleton(SpendCollector::class, fn (Application $app) => $app->make(ContextSpendCollector::class));

        $this->app->bind(GenerationCostResolver::class, function (Application $app) {
            $config = $app['config']->get('ai-kit.spend', []);
            $provider = $app['config']->get('ai.providers.'.($config['provider'] ?? 'openrouter'), []);

            return new GenerationCostResolver(
                $app->make(HttpFactory::class),
                $provider['key'] ?? null,
                $provider['url'] ?? 'https://openrouter.ai/api/v1',
                windowSeconds: (float) ($config['sync_window_seconds'] ?? 6),
                backoffMs: array_values(array_map('intval', $config['sync_backoff_ms'] ?? [500, 1000, 1500, 3000])),
                requestTimeoutSeconds: (int) ($config['request_timeout_seconds'] ?? 3),
            );
        });

        // Apps bind their own handler (debit a stopped turn, record a failed
        // one); bindIf so the order the providers register in never matters.
        $this->app->bindIf(InterruptedSpendHandler::class, NullInterruptedSpendHandler::class);

        $this->app->bind(InterruptedSpend::class, fn (Application $app) => new InterruptedSpend(
            $app->make(SpendCollector::class),
            $app->make(GenerationCostResolver::class),
            $app,
            $app['cache']->store(
                $app['config']->get('ai-kit.spend.cache_store') ?? $app['config']->get('ai-kit.safety.cache_store'),
            ),
            $app['config']->get('ai-kit.spend', []),
        ));

        $this->app->singleton(ModelCircuitBreaker::class, function (Application $app) {
            $config = $app['config']->get('ai-kit.gateway.circuit_breaker', []);

            return new ModelCircuitBreaker(
                $app['cache']->store($config['cache_store'] ?? null),
                failureThreshold: (int) ($config['failure_threshold'] ?? 5),
                windowSeconds: (int) ($config['window_seconds'] ?? 120),
                cooldownSeconds: (int) ($config['cooldown_seconds'] ?? 60),
                halfOpenSeconds: (int) ($config['half_open_seconds'] ?? 30),
            );
        });

        $this->app->bind(ReasoningOpenRouterGateway::class, fn (Application $app) => new ReasoningOpenRouterGateway(
            $app['events'],
            $app->make(SpendCollector::class),
            $app['config']->get('ai-kit.gateway', []),
            $app['config']->get('ai-kit.gateway.circuit_breaker.enabled', true)
                ? $app->make(ModelCircuitBreaker::class)
                : null,
            // The catalog module is optional; without it there are no chains
            // or price caps to declare and the body stays bare.
            $app->bound(ModelRouting::class) ? $app->make(ModelRouting::class) : null,
            // The step guard's wrap-up knobs live with the chat defaults.
            $app['config']->get('ai-kit.chat', []),
        ));

        if ($this->app['config']->get('ai-kit.gateway.register_openrouter_driver', true)) {
            $this->registerOpenRouterDriver();
        }
    }

    /**
     * Re-register the openrouter driver with the stock provider but our
     * text gateway. Custom creators win over the built-in driver, so this
     * is the same seam all three apps already use.
     */
    protected function registerOpenRouterDriver(): void
    {
        Ai::extend('openrouter', fn (Application $app, array $config): OpenRouterProvider => (new OpenRouterProvider(
            $config,
            $app->make(Dispatcher::class),
        ))->useTextGateway($app->make(ReasoningOpenRouterGateway::class)));
    }
}

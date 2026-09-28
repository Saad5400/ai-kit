<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenRouterProvider;
use Saad\AiKit\Catalog\ModelRouting;
use Saad\AiKit\Support\TurnContext;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContextSpendCollector::class, fn (Application $app) => new ContextSpendCollector(
            $app['config']->get('ai-kit.gateway.spend_context_prefix', 'ai'),
        ));

        $this->app->singleton(SpendCollector::class, fn (Application $app) => $app->make(ContextSpendCollector::class));

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

    public function boot(): void
    {
        // Laravel serialises Context into every job dispatched mid-turn and
        // re-hydrates it in the job: a turn's spend (and its pending
        // generations, its turn id and meta) must never ride along, or the
        // job's own usage row — or a second settlement under the same turn
        // id — would count it again. The dispatching turn keeps its values:
        // dehydrate() works on a copy.
        Context::dehydrating(function (ContextRepository $context): void {
            $spend = $this->app->make(ContextSpendCollector::class);

            $context->forget([
                $spend->costsKey(),
                $spend->nonStreamCostsKey(),
                $spend->generationIdsKey(),
                $spend->nonStreamGenerationIdsKey(),
                $spend->pendingGenerationIdsKey(),
            ]);

            $context->forgetHidden([TurnContext::TURN_ID_KEY, TurnContext::TURN_META_KEY]);
        });
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

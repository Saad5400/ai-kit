<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenRouterProvider;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SpendCollector::class, fn (Application $app) => new ContextSpendCollector(
            $app['config']->get('ai-kit.gateway.spend_context_prefix', 'ai'),
        ));

        $this->app->bind(ReasoningOpenRouterGateway::class, fn (Application $app) => new ReasoningOpenRouterGateway(
            $app['events'],
            $app->make(SpendCollector::class),
            $app['config']->get('ai-kit.gateway', []),
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

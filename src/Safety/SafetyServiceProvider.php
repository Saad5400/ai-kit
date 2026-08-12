<?php

namespace Saad\AiKit\Safety;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class SafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KillSwitch::class, fn (Application $app) => new KillSwitch(
            $this->cache($app),
            $app['events'],
        ));

        $this->app->singleton(BudgetGuard::class, fn (Application $app) => new BudgetGuard(
            $this->cache($app),
            $app['events'],
            $app['config']->get('ai-kit.safety.daily_usd_limit'),
            $app['config']->get('ai-kit.safety.timezone'),
        ));

        $this->app->singleton(TurnConcurrencyLimiter::class, fn (Application $app) => new TurnConcurrencyLimiter(
            $this->cache($app),
            $app['config']->get('ai-kit.safety.max_concurrent_turns'),
            $app['config']->get('ai-kit.safety.turn_ttl_seconds', 600),
        ));
    }

    protected function cache(Application $app): Repository
    {
        return $app['cache']->store($app['config']->get('ai-kit.safety.cache_store'));
    }
}

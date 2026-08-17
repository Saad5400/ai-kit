<?php

namespace Saad\AiKit\Safety;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Safety\Listeners\RecordBudgetSpend;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;

class SafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Apps with an operator-editable settings store (Spatie settings,
        // a DB table) rebind this contract in their own provider.
        $this->app->singleton(SafetySettings::class, ConfigSafetySettings::class);

        $this->app->singleton(KillSwitch::class, fn (Application $app) => new KillSwitch(
            $this->cache($app),
            $app['events'],
            $app->make(SafetySettings::class),
        ));

        $this->app->singleton(BudgetGuard::class, fn (Application $app) => new BudgetGuard(
            $this->cache($app),
            $app['events'],
            $app['config']->get('ai-kit.safety.daily_usd_limit'),
            $app['config']->get('ai-kit.safety.timezone'),
            $app->make(SafetySettings::class),
        ));

        $this->app->singleton(TurnConcurrencyLimiter::class, fn (Application $app) => new TurnConcurrencyLimiter(
            $this->cache($app),
            $app['config']->get('ai-kit.safety.max_concurrent_turns'),
            $app['config']->get('ai-kit.safety.turn_ttl_seconds', 600),
        ));

        $this->app->singleton(TurnGuard::class);
    }

    public function boot(): void
    {
        // Budget enforcement is only as good as its counter: when the
        // usage module meters turns, feed each recorded cost into the
        // BudgetGuard. The `record_spend_from_usage` toggle is honored at
        // handle time so it can be flipped at runtime.
        if ($this->app['config']->get('ai-kit.modules.usage') === true) {
            Event::listen(TurnUsageRecorded::class, RecordBudgetSpend::class);
        }
    }

    protected function cache(Application $app): Repository
    {
        return $app['cache']->store($app['config']->get('ai-kit.safety.cache_store'));
    }
}

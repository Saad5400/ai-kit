<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\PlanStore;
use Saad\AiKit\Approvals\Contracts\UndoLedger;

class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the concrete registry and alias the contract to it, so an app
        // resolving either gets the SAME instance to register actions on.
        $this->app->singleton(ArrayActionRegistry::class);
        $this->app->alias(ArrayActionRegistry::class, ActionRegistry::class);

        // Scoped (not singleton): the agent and every tool it runs in one
        // turn share one bag/gate, and Octane resets them per request/job so
        // no mode or collected write leaks across turns on a reused worker.
        $this->app->scoped(ProposalBag::class);
        $this->app->scoped(WriteGate::class);

        // No-op until the undo milestone; apps with a real ledger rebind.
        $this->app->singleton(UndoLedger::class, NullUndoLedger::class);

        $this->app->singleton(PlanStore::class, function ($app) {
            $config = $app['config'];

            return new CachePlanStore(
                $app['cache']->store($config->get('ai-kit.approvals.plan_cache_store')),
                (int) $config->get('ai-kit.approvals.plan_ttl_seconds', 3600),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'ai-kit-migrations');
    }
}

<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\PlanStore;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\CompensationApplier;
use Saad\AiKit\Approvals\Undo\DatabaseUndoLedger;
use Saad\AiKit\Approvals\Undo\UndoTurn;

/**
 * TRANSITIONAL (owner ruling 2026-08-17, docs/DECISIONS.md #3): the decided
 * approval contract is laravel/ai's native `Approvable` classified pause —
 * safe+undoable writes execute immediately, destructive ones pause the turn.
 * This propose → confirm → execute machinery shipped while that ruling was
 * not visible; it stays in prod until the M5 Approvable rework lands, and the
 * rework must keep its real wins: server-derived `destructive`, the idempotent
 * execution ledger, and preview == execution.
 */
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

        // Turn undo is opt-in: the database ledger + replay only when the
        // app asked for the table; the no-op default keeps the `undoable`
        // flag flowing everywhere else.
        $this->app->singleton(
            UndoLedger::class,
            fn ($app) => $app->make(
                $app['config']->get('ai-kit.approvals.undo') === true
                    ? DatabaseUndoLedger::class
                    : NullUndoLedger::class,
            ),
        );

        $this->app->singleton(CompensationApplier::class);
        $this->app->singleton(UndoTurn::class);

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
        // The undo ledger's table exists only for apps that opted in.
        if ($this->app['config']->get('ai-kit.approvals.undo') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/undo');
        }
    }
}

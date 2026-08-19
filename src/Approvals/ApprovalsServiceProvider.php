<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\PlanStore;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\CompensationApplier;
use Saad\AiKit\Approvals\Undo\DatabaseUndoLedger;
use Saad\AiKit\Approvals\Undo\UndoTurn;
use Saad\AiKit\Support\LoadsKitMigrations;

/**
 * Two approval seams (owner ruling, docs/DECISIONS.md #3):
 *
 * - `Classified\*` is the DECIDED contract — the classified pause on
 *   laravel/ai's native `Approvable` ({@see Classified\ClassifiedTool}).
 *   It needs no bindings of its own; it rides the WriteExecutions ledger
 *   and the UndoLedger registered here.
 * - The propose → confirm → execute machinery (Proposal / WriteGate /
 *   Plan…) is TRANSITIONAL: it shipped while the ruling was not visible,
 *   stays until the apps running it (uqucc) migrate onto the classified
 *   seam, then it is retired. Its wins — server-derived `destructive`,
 *   idempotent execution, preview == execution — live on in the seam.
 */
class ApprovalsServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

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
        // Reaching boot() at all is the module gate: the root provider only
        // registers this provider when `ai-kit.modules.approvals` is true.
        $this->loadKitMigrations(__DIR__.'/../../database/migrations/approvals');

        // The undo ledger's table exists only for apps that opted in.
        if ($this->app['config']->get('ai-kit.approvals.undo') === true) {
            $this->loadKitMigrations(__DIR__.'/../../database/migrations/undo');
        }
    }
}

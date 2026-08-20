<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\CompensationApplier;
use Saad\AiKit\Approvals\Undo\DatabaseUndoLedger;
use Saad\AiKit\Approvals\Undo\UndoTurn;
use Saad\AiKit\Support\LoadsKitMigrations;

/**
 * One approval seam (owner ruling, docs/DECISIONS.md #3): `Classified\*`,
 * the classified pause on laravel/ai's native `Approvable`
 * ({@see Classified\ClassifiedTool}). Tools declare a server-derived
 * Capability; reads run free, undoable writes execute immediately, and
 * destructive calls pause the turn until a human decides.
 *
 * The seam needs no bindings of its own — it rides the exactly-once
 * {@see WriteExecutions} ledger and the {@see UndoLedger} registered here,
 * so all this provider does is choose the undo implementation and load the
 * module's migrations.
 *
 * The transitional propose → confirm → execute machinery (Proposal /
 * WriteGate / Plan…) that shipped in v0.3.0 while the ruling was not
 * visible was RETIRED in v0.8.0, once both consumers had migrated. Its
 * wins — server-derived `destructive`, idempotent execution, preview ==
 * execution — live on in the seam.
 */
class ApprovalsServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

    public function register(): void
    {
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

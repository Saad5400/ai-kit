<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Contracts\Container\Container;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\ProposableAction;

/**
 * The default in-memory {@see ActionRegistry}. Apps register their actions
 * once at boot (instances or class names — class names resolve through the
 * container lazily, so an action with constructor dependencies costs nothing
 * until it is actually looked up).
 */
class ArrayActionRegistry implements ActionRegistry
{
    /** @var array<string, ProposableAction> */
    protected array $actions = [];

    /** @var list<class-string<ProposableAction>> */
    protected array $deferred = [];

    public function __construct(protected Container $container) {}

    public function register(ProposableAction|string ...$actions): void
    {
        foreach ($actions as $action) {
            if ($action instanceof ProposableAction) {
                $this->actions[$action->type()] = $action;
            } else {
                $this->deferred[] = $action;
            }
        }
    }

    public function get(string $type): ?ProposableAction
    {
        $this->resolveDeferred();

        return $this->actions[$type] ?? null;
    }

    /**
     * All registered actions, keyed by type.
     *
     * @return array<string, ProposableAction>
     */
    public function all(): array
    {
        $this->resolveDeferred();

        return $this->actions;
    }

    protected function resolveDeferred(): void
    {
        foreach ($this->deferred as $class) {
            $action = $this->container->make($class);

            $this->actions[$action->type()] = $action;
        }

        $this->deferred = [];
    }
}

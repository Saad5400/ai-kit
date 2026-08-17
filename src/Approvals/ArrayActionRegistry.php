<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Contracts\Container\Container;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\ProposableAction;

/**
 * The default in-memory {@see ActionRegistry}. Apps register their actions
 * once at boot (instances or class names — class names resolve through the
 * container on the first lookup, so an action with constructor dependencies
 * costs nothing until the registry is actually used).
 *
 * Registrations are kept in one ordered list precisely so the LATEST one for
 * a type wins whichever form each took: an app overriding a kit action by
 * registering its own instance afterwards must not be undone by a class
 * name registered earlier resolving later.
 */
class ArrayActionRegistry implements ActionRegistry
{
    /** @var list<ProposableAction|class-string<ProposableAction>> in registration order */
    protected array $registrations = [];

    /** @var array<string, ProposableAction>|null the resolved map; invalidated by register() */
    protected ?array $resolved = null;

    public function __construct(protected Container $container) {}

    public function register(ProposableAction|string ...$actions): void
    {
        foreach ($actions as $action) {
            $this->registrations[] = $action;
        }

        $this->resolved = null;
    }

    public function get(string $type): ?ProposableAction
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * All registered actions, keyed by type.
     *
     * @return array<string, ProposableAction>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->registrations as $index => $action) {
            if (is_string($action)) {
                // Memoized in place: re-resolving on a later register() would
                // hand out a different instance for the same registration.
                $action = $this->registrations[$index] = $this->container->make($action);
            }

            $resolved[$action->type()] = $action;
        }

        return $this->resolved = $resolved;
    }
}

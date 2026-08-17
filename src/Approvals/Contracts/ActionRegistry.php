<?php

namespace Saad\AiKit\Approvals\Contracts;

/**
 * Resolves a {@see ProposableAction} by its stable type string — the seam the
 * executor uses to re-resolve a STORED proposal's action at confirm time, so
 * executing never re-invokes the model.
 */
interface ActionRegistry
{
    public function get(string $type): ?ProposableAction;

    /**
     * Register actions, given as instances or container-resolvable class
     * names (class names resolve lazily, on first {@see self::get()}).
     *
     * @param  ProposableAction|class-string<ProposableAction>  ...$actions
     */
    public function register(ProposableAction|string ...$actions): void;
}

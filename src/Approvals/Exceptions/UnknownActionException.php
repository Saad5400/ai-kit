<?php

namespace Saad\AiKit\Approvals\Exceptions;

use InvalidArgumentException;
use Saad\AiKit\Approvals\Contracts\ProposableAction;

/**
 * A type string no {@see ProposableAction}
 * was registered for — a wiring error at propose/build time (at confirm time
 * an unknown type marks the proposal failed instead, since the action may
 * simply have been retired between deploys).
 */
class UnknownActionException extends InvalidArgumentException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("No proposable action is registered for type [{$type}].");
    }
}

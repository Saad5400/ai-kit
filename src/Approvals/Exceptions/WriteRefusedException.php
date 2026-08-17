<?php

namespace Saad\AiKit\Approvals\Exceptions;

use RuntimeException;
use Saad\AiKit\Approvals\ProposedWrite;
use Saad\AiKit\Approvals\WriteGate;

/**
 * The {@see WriteGate} refused a write during an
 * execute turn: it deviated from the approved plan (a fresh destructive
 * step) or targeted something outside the approved scope. The message is a
 * tool-error string apps relay to the model so it can stop and steer the
 * user back through propose.
 */
class WriteRefusedException extends RuntimeException
{
    public const REASON_OUT_OF_PLAN = 'out_of_plan';

    public const REASON_OUT_OF_SCOPE = 'out_of_scope';

    public function __construct(
        public readonly ProposedWrite $write,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}

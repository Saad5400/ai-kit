<?php

namespace Saad\AiKit\Approvals\Exceptions;

use RuntimeException;
use Saad\AiKit\Approvals\Proposal;

/**
 * Confirm/reject was attempted on a proposal that is no longer pending (it
 * was already confirmed, rejected, or failed). Carries the CURRENT proposal
 * so apps can map this to a 409 response whose body is the up-to-date card —
 * the client repaints instead of guessing.
 */
class ProposalNotPendingException extends RuntimeException
{
    public function __construct(public readonly Proposal $proposal)
    {
        parent::__construct(
            "Proposal [{$proposal->getKey()}] is not pending (status: {$proposal->status->value}).",
        );
    }
}

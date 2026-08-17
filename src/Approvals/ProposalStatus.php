<?php

namespace Saad\AiKit\Approvals;

/**
 * The lifecycle of a stored proposal (and of a plan): persisted `pending` at
 * propose time; a human confirming runs it (→ `confirmed`, or `failed` with
 * the error surfaced); declining marks it `rejected`. Terminal states never
 * transition again — a second confirm is a 409, not a re-run.
 */
enum ProposalStatus: string
{
    case Pending = 'pending';

    case Confirmed = 'confirmed';

    case Rejected = 'rejected';

    case Failed = 'failed';
}

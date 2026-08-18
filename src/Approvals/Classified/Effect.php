<?php

namespace Saad\AiKit\Approvals\Classified;

/**
 * What a tool call does to the world, as the SERVER classifies it — the
 * classification drives the classified pause (owner decision #3) and is
 * never model-visible or model-supplied, which is what makes `destructive`
 * on the client card trustworthy.
 */
enum Effect: string
{
    /** Observes state; never gated. */
    case Read = 'read';

    /** Changes state reversibly-in-principle; auto-executes only when undoable AND ledgered. */
    case Write = 'write';

    /** Changes state irreversibly (or destroys it); always pauses the turn. */
    case Destructive = 'destructive';
}

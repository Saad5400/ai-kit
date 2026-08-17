<?php

namespace Saad\AiKit\Approvals;

use Saad\AiKit\Approvals\Contracts\UndoLedger;

/**
 * The default {@see UndoLedger}: records nothing. Full undo lands in a later
 * milestone; until then the contract keeps the `undoable` flag flowing and
 * gives apps with an existing ledger a binding point.
 */
class NullUndoLedger implements UndoLedger
{
    public function record(
        string $actionType,
        array $input,
        mixed $result,
        bool $undoable,
        ?string $turnId = null,
        ?int $sequence = null,
    ): void {
        //
    }
}

<?php

namespace Saad\AiKit\Approvals;

use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\UndoRecord;

/**
 * The default {@see UndoLedger}: records nothing. Turn undo is opt-in via
 * `ai-kit.approvals.undo`; until an app opts in, the contract keeps the
 * `undoable` flag flowing and gives apps with their own ledger a binding
 * point.
 */
class NullUndoLedger implements UndoLedger
{
    public function record(UndoRecord $record): void
    {
        //
    }
}

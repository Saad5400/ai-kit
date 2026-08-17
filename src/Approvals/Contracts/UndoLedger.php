<?php

namespace Saad\AiKit\Approvals\Contracts;

use Saad\AiKit\Approvals\NullUndoLedger;
use Saad\AiKit\Approvals\Undo\DatabaseUndoLedger;
use Saad\AiKit\Approvals\Undo\UndoRecord;
use Saad\AiKit\Approvals\Undo\UndoTurn;

/**
 * The undo seam: every executed write reports itself here with its
 * compensation and `undoable` flag. The default binding is the no-op
 * {@see NullUndoLedger}; setting
 * `ai-kit.approvals.undo` to true swaps in the database ledger
 * ({@see DatabaseUndoLedger}) that
 * {@see UndoTurn} replays.
 */
interface UndoLedger
{
    public function record(UndoRecord $record): void;
}

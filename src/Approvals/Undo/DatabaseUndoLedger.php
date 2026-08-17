<?php

namespace Saad\AiKit\Approvals\Undo;

use Saad\AiKit\Approvals\Contracts\UndoLedger;

/**
 * Persists each executed write's compensation so {@see UndoTurn} can replay
 * a turn in reverse. A record without a turn id is fire-and-forget (MCP —
 * external clients have their own history and no undo surface) and is not
 * ledgered.
 */
class DatabaseUndoLedger implements UndoLedger
{
    public function record(UndoRecord $record): void
    {
        if ($record->turnId === null) {
            return;
        }

        UndoAction::query()->create([
            'turn_id' => $record->turnId,
            'sequence' => $record->sequence,
            'owner' => (string) $record->owner,
            'action_type' => $record->actionType,
            'target_type' => $record->targetType,
            'target_id' => $record->targetId !== null ? (string) $record->targetId : null,
            'compensation' => $record->compensation,
            'undoable' => $record->undoable,
            'not_undoable_reason' => $record->notUndoableReason,
        ]);
    }
}

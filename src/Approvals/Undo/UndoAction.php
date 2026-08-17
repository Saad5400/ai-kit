<?php

namespace Saad\AiKit\Approvals\Undo;

use Illuminate\Database\Eloquent\Model;

/**
 * One undo-ledger row: write-once (no updated_at), deleted in bulk when its
 * turn is undone (one-shot — undo can never replay into a double-revert).
 * `owner` is the same string owner key as Proposal.proposed_by, and is part
 * of every read so one user can never undo another's turn.
 *
 * @property int $id
 * @property string $turn_id
 * @property int $sequence
 * @property string $owner
 * @property string $action_type
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $compensation
 * @property bool $undoable
 * @property string|null $not_undoable_reason
 */
class UndoAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-kit.approvals.undo_table', 'ai_undo_actions');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'compensation' => 'array',
            'undoable' => 'boolean',
            'sequence' => 'integer',
        ];
    }
}

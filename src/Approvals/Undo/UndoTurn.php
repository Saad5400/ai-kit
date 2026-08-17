<?php

namespace Saad\AiKit\Approvals\Undo;

use Throwable;

/**
 * Replay a whole turn's compensations in reverse — a later write is undone
 * before the earlier one it may depend on. Non-undoable rows are SKIPPED
 * with their stored reason, never silently dropped, so the caller can show
 * the user exactly what could not be rolled back. One-shot: every row for
 * (turn, owner) is deleted afterwards, whatever happened, so undo can never
 * replay into a double-revert.
 *
 * Undo should spend nothing and sit OUTSIDE any credit gate — a lapsed or
 * credit-empty user must still be able to revert.
 */
class UndoTurn
{
    public function __construct(protected CompensationApplier $applier) {}

    /**
     * @return array{reverted: int, skipped: list<array{action_type: string, reason: string}>}
     */
    public function handle(string $turnId, string $owner): array
    {
        $actions = UndoAction::query()
            ->where('turn_id', $turnId)
            ->where('owner', $owner)
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->get();

        $reverted = 0;
        $skipped = [];

        foreach ($actions as $action) {
            if (! $action->undoable || ! is_array($action->compensation)) {
                $skipped[] = [
                    'action_type' => $action->action_type,
                    'reason' => $action->not_undoable_reason ?? __('ai-kit::approvals.undo_unsupported'),
                ];

                continue;
            }

            try {
                $this->applier->apply($action->compensation);
                $reverted++;
            } catch (Throwable $exception) {
                report($exception);

                $skipped[] = [
                    'action_type' => $action->action_type,
                    'reason' => __('ai-kit::approvals.undo_failed'),
                ];
            }
        }

        UndoAction::query()
            ->where('turn_id', $turnId)
            ->where('owner', $owner)
            ->delete();

        return ['reverted' => $reverted, 'skipped' => $skipped];
    }
}

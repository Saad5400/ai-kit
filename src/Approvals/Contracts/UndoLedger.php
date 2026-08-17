<?php

namespace Saad\AiKit\Approvals\Contracts;

/**
 * The undo seam: every executed write reports itself here with its
 * `undoable` flag. The kit ships only a no-op default — real compensation
 * (s-grade's ledger + CompensationPlanner shape) is a later milestone. The
 * contract exists NOW so the flag flows end to end and apps that already
 * have an undo ledger can plug it in.
 */
interface UndoLedger
{
    /**
     * @param  array<string, mixed>  $input  the normalized input that executed
     * @param  mixed  $result  whatever the action's execute() returned
     */
    public function record(
        string $actionType,
        array $input,
        mixed $result,
        bool $undoable,
        ?string $turnId = null,
        ?int $sequence = null,
    ): void;
}

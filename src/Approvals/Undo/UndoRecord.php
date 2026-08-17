<?php

namespace Saad\AiKit\Approvals\Undo;

/**
 * One executed write, as the undo ledger sees it. `compensation` is the
 * self-describing reversal op ({op: ..., ...} — the model class travels in
 * the payload); a null compensation with `undoable` false plus a translated
 * `notUndoableReason` records "we can't take this back and here's why",
 * which the undo turn surfaces instead of silently dropping. `turnId` null
 * means fire-and-forget (MCP): nothing is ledgered at all.
 */
final class UndoRecord
{
    /**
     * @param  array<string, mixed>|null  $compensation
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $actionType,
        public readonly bool $undoable,
        public readonly ?string $turnId = null,
        public readonly int $sequence = 0,
        public readonly ?string $owner = null,
        public readonly ?array $compensation = null,
        public readonly ?string $targetType = null,
        public readonly int|string|null $targetId = null,
        public readonly ?string $notUndoableReason = null,
        public readonly array $input = [],
        public readonly mixed $result = null,
    ) {}
}

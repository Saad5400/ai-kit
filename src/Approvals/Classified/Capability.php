<?php

namespace Saad\AiKit\Approvals\Classified;

/**
 * A tool's server-derived write classification: its {@see Effect}, whether
 * an execution can be compensated (`undoable`), and the operator-facing
 * reason shown on the approval card. Owner decision #3's classified pause
 * reads straight off this object:
 *
 * - read            → never pauses
 * - write, undoable → executes immediately WHEN the undo ledger is live
 *                     (the ledger is the net that makes immediate safe);
 *                     without the ledger the app gets the narrower tier and
 *                     the write pauses like everything else (decision #5)
 * - destructive     → always pauses the turn
 */
final class Capability
{
    private function __construct(
        public readonly Effect $effect,
        public readonly bool $undoable,
        public readonly ?string $reason,
    ) {}

    public static function read(): self
    {
        return new self(Effect::Read, undoable: false, reason: null);
    }

    public static function write(bool $undoable = false, ?string $reason = null): self
    {
        return new self(Effect::Write, $undoable, $reason);
    }

    public static function destructive(?string $reason = null): self
    {
        return new self(Effect::Destructive, undoable: false, reason: $reason);
    }

    /**
     * Whether a call with this capability pauses the turn. `$undoLedgered`
     * is whether the app runs the database undo ledger — the auto-execute
     * tier only exists on top of it.
     */
    public function requiresApproval(bool $undoLedgered): bool
    {
        return match ($this->effect) {
            Effect::Read => false,
            Effect::Write => ! ($this->undoable && $undoLedgered),
            Effect::Destructive => true,
        };
    }
}

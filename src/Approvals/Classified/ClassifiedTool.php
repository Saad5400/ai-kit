<?php

namespace Saad\AiKit\Approvals\Classified;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Contracts\Classified;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\UndoRecord;
use Saad\AiKit\Approvals\WriteExecutions;
use Saad\AiKit\Support\TurnContext;
use Stringable;

/**
 * The decided approval seam (owner decision #3): a laravel/ai tool whose
 * pause behaviour is derived from its {@see Capability} classification —
 * reads run free, undoable writes execute immediately and land in the undo
 * ledger, destructive calls pause the turn through laravel/ai's native
 * `Approvable` and resume via `Decisions`.
 *
 * What the transitional proposal module got right survives here:
 *
 * - `destructive` is server-derived: {@see capability()} is code the model
 *   never sees, so the card's classification cannot be prompted around.
 * - Execution is idempotent: gated effects claim a {@see WriteExecutions}
 *   ledger row keyed by the provider tool-call id — a queue-retried resume
 *   or a double-submitted decision replays the first committed result
 *   instead of writing twice.
 * - Preview == execution: the card renders from the SAME stored tool-call
 *   arguments the resume executes (or the user's edited arguments, which
 *   travel the same validated tool path). {@see preview()} receives exactly
 *   the request {@see perform()} will.
 *
 * Implementations write {@see perform()} (the effect itself),
 * {@see capability()} (the classification) and the vendor contract's
 * description/schema. Compensation for the undo ledger comes from
 * {@see compensation()}; card copy from {@see title()} / {@see preview()}.
 */
abstract class ClassifiedTool implements Approvable, Classified, Tool
{
    use InteractsWithApprovals;

    /**
     * Perform the tool's effect. For gated effects this runs inside the
     * exactly-once transaction; whatever string it returns is what the
     * model reads back.
     */
    abstract protected function perform(Request $request): Stringable|string;

    /**
     * The classified pause, resolved per call and entirely server-side.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->capability()->requiresApproval($this->undoLedgered())) {
            return false;
        }

        return Approval::required($this->approvalReason($request));
    }

    final public function handle(Request $request): Stringable|string
    {
        if ($this->capability()->effect === Effect::Read) {
            return $this->perform($request);
        }

        $callId = $request->toolCallId();

        // No provider call id means no replay to guard against (a direct
        // invocation outside a generation loop): execute transactionally
        // and ledger with a null turn id, which the undo ledger reads as
        // fire-and-forget.
        if ($callId === null) {
            $output = (string) DB::transaction(fn () => $this->perform($request));

            $this->ledgerUndo($request, $output, turnId: null);

            return $output;
        }

        ['replayed' => $replayed, 'result' => $result] = app(WriteExecutions::class)->execute(
            turnId: 'tool:'.$callId,
            sequence: 0,
            actionType: $this->name(),
            executedBy: $this->executedBy(),
            write: fn (): array => [
                'output' => (string) $this->perform($request),
                'edited_by_user' => ResumeDecisions::wasEdited($callId),
            ],
            undoable: $this->capability()->undoable,
        );

        $output = (string) ($result['output'] ?? '');

        if (! $replayed) {
            $this->ledgerUndo($request, $output, turnId: $this->turnId($callId));
        }

        return $output;
    }

    /**
     * The self-describing compensation op for the undo ledger, or null when
     * this execution cannot be taken back ({@see notUndoableReason} then
     * explains why on the undo surface).
     *
     * @return array<string, mixed>|null
     */
    protected function compensation(Request $request, string $result): ?array
    {
        return null;
    }

    /**
     * Why an executed call cannot be undone — surfaced, translated, by the
     * undo turn instead of silently dropping the record.
     */
    protected function notUndoableReason(Request $request): ?string
    {
        return null;
    }

    /**
     * Card headline. Server-derived like everything else on the card.
     */
    public function title(Request $request): string
    {
        return Str::headline(class_basename(static::class));
    }

    /**
     * Human-readable lines describing exactly what will execute, rendered
     * from the same request the execution will receive.
     *
     * @return list<string>
     */
    public function preview(Request $request): array
    {
        return [];
    }

    /**
     * The vendor's tool-name convention (ToolNameResolver honours name()).
     */
    public function name(): string
    {
        return class_basename(static::class);
    }

    /**
     * The operator-facing reason on the approval card.
     */
    protected function approvalReason(Request $request): ?string
    {
        return $this->capability()->reason;
    }

    /**
     * Who executes — an app principal identifier for the audit trail.
     */
    protected function executedBy(): ?string
    {
        return null;
    }

    protected function undoLedgered(): bool
    {
        return config('ai-kit.approvals.undo') === true;
    }

    /**
     * The undo-grouping turn id: the usage module's current invocation when
     * one is stamped (all of one agent turn's writes group together), the
     * tool-call id otherwise.
     */
    protected function turnId(string $callId): string
    {
        return (string) (Context::get(TurnContext::CURRENT_INVOCATION_KEY) ?? $callId);
    }

    protected function ledgerUndo(Request $request, string $result, ?string $turnId): void
    {
        app(UndoLedger::class)->record(new UndoRecord(
            actionType: $this->name(),
            undoable: $this->capability()->undoable,
            turnId: $turnId,
            owner: $this->executedBy(),
            compensation: $this->compensation($request, $result),
            notUndoableReason: $this->capability()->undoable ? null : $this->notUndoableReason($request),
            input: $request->all(),
            result: $result,
        ));
    }
}

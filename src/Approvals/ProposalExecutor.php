<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\Facades\DB;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Exceptions\ActionValidationException;
use Saad\AiKit\Approvals\Exceptions\ProposalNotPendingException;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;
use Throwable;

/**
 * The proposal lifecycle service — both phases of the two-phase write.
 *
 * `propose()` validates raw input through the registered action and persists
 * a PENDING {@see Proposal}, never touching live state. `confirm()` applies
 * a stored proposal: the type is re-resolved through the {@see ActionRegistry}
 * and the STORED raw input is RE-VALIDATED against the CURRENT state (state
 * may have changed since propose time), then executed inside a transaction —
 * the model is never re-invoked, so what executes is what was previewed.
 * Success → `confirmed`; any expected failure → `failed` with the error
 * surfaced for the action card. A non-pending proposal throws
 * {@see ProposalNotPendingException} carrying the current row (apps map it
 * to a 409 whose body is the up-to-date card).
 */
class ProposalExecutor
{
    public function __construct(
        protected readonly ActionRegistry $registry,
        protected readonly UndoLedger $undo,
    ) {}

    /**
     * Phase one: validate and persist a pending proposal for a human to
     * confirm. The RAW input is stored (re-validated at confirm time); the
     * normalized result becomes the card's preview/details.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws UnknownActionException when no action is registered for $type
     * @throws ActionValidationException when the input cannot be applied
     */
    public function propose(string $type, array $input, mixed $actor, string $proposedBy): Proposal
    {
        $action = $this->registry->get($type) ?? throw new UnknownActionException($type);

        $normalized = $action->validate($input, $actor);

        return Proposal::query()->create([
            'type' => $action->type(),
            'category' => $action->category(),
            'payload' => [
                'action' => $action->type(),
                'category' => $action->category(),
                'input' => $input,
                'preview' => $normalized,
            ],
            'summary' => $action->summarize($normalized, $actor),
            'status' => ProposalStatus::Pending,
            'proposed_by' => $proposedBy,
        ]);
    }

    /**
     * Phase two: apply a pending proposal. On success it becomes
     * `confirmed`; any failure (unknown/retired action, stale ids, database
     * error) marks it `failed` with the error surfaced for the card.
     *
     * @throws ProposalNotPendingException when the proposal already left `pending`
     */
    public function confirm(Proposal $proposal, mixed $actor): Proposal
    {
        if (! $proposal->isPending()) {
            throw new ProposalNotPendingException($proposal);
        }

        try {
            DB::transaction(function () use ($proposal, $actor): void {
                $action = $this->registry->get($proposal->type);

                if ($action === null) {
                    throw new ActionValidationException('The proposed action type is unknown or no longer supported.');
                }

                /** @var array<string, mixed> $input */
                $input = (array) ($proposal->payload['input'] ?? []);

                $normalized = $action->validate($input, $actor);

                $result = $action->execute($normalized, $actor);

                $this->undo->record($proposal->type, $normalized, $result, $action->undoable());

                $proposal->update([
                    'status' => ProposalStatus::Confirmed,
                    'executed_at' => now(),
                    'error' => null,
                ]);
            });
        } catch (ActionValidationException $exception) {
            $proposal->update([
                'status' => ProposalStatus::Failed,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $proposal->update([
                'status' => ProposalStatus::Failed,
                'error' => 'An unexpected error occurred while executing the proposal.',
            ]);
        }

        return $proposal->refresh();
    }

    /**
     * Decline a pending proposal; nothing is applied.
     *
     * @throws ProposalNotPendingException when the proposal already left `pending`
     */
    public function reject(Proposal $proposal): Proposal
    {
        if (! $proposal->isPending()) {
            throw new ProposalNotPendingException($proposal);
        }

        $proposal->update(['status' => ProposalStatus::Rejected]);

        return $proposal->refresh();
    }
}

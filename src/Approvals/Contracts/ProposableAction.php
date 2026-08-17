<?php

namespace Saad\AiKit\Approvals\Contracts;

use Saad\AiKit\Approvals\Exceptions\ActionValidationException;

/**
 * One write the assistant may perform through the propose → confirm → execute
 * flow. The SAME implementation serves both phases: propose validates and
 * summarizes without touching live state; confirm re-validates against the
 * CURRENT state and executes inside a transaction — so what executes is what
 * was previewed, or the proposal fails with the reason.
 *
 * The actor is deliberately untyped (`mixed`): apps pass their User model, an
 * owner key string ("telegram:{id}"), or null for system-initiated writes.
 */
interface ProposableAction
{
    /**
     * The stable action identifier the registry resolves and proposals store
     * as `type` (e.g. "update_settings"). It travels through the client and
     * back, so it must stay stable across deploys.
     */
    public function type(): string;

    /**
     * The group the client renders the action card under (e.g. "settings").
     */
    public function category(): string;

    /**
     * Whether executing hard-deletes or otherwise destroys records. This is
     * the server-side truth — plan steps and gate decisions derive from it,
     * never from a model-supplied flag.
     */
    public function destructive(): bool;

    /**
     * Whether the write can be compensated after execution. Flows into the
     * undo ledger and plan auto-approval; full undo lands in a later
     * milestone, the flag is carried now so client payloads are stable.
     */
    public function undoable(): bool;

    /**
     * The exact string the user must retype to approve a catastrophic
     * variant of this write (e.g. the record's real name for a cascade
     * delete), resolved server-side from the input. Null for everything
     * else — the plain confirm suffices.
     */
    public function typedConfirmPhrase(array $input, mixed $actor): ?string;

    /**
     * Validate the raw input against the current state and return the
     * normalized input — the preview the client shows under the summary.
     * Runs at propose time AND again at confirm time (state may have
     * changed in between).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ActionValidationException when the input cannot be applied
     */
    public function validate(array $input, mixed $actor): array;

    /**
     * One human-readable line describing what the write will do, built from
     * the normalized input.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, mixed $actor): string;

    /**
     * Apply the write for real. Only ever called by the executor, inside a
     * transaction, after a fresh {@see self::validate()} passed.
     *
     * @param  array<string, mixed>  $input  the freshly normalized input
     *
     * @throws ActionValidationException for expected, user-facing failures
     */
    public function execute(array $input, mixed $actor): mixed;
}

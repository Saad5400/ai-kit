<?php

namespace Saad\AiKit\Approvals\Classified;

use Closure;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

/**
 * Maps the client's decision payload onto laravel/ai `Decisions` for the
 * resume prompt (`$agent->prompt(ResumeDecisions::fromClient($input))`),
 * and carries the ONE fact the vendor loop drops on the floor: which calls
 * the user edited. Edited ids are stamped into Context so the executing
 * {@see ClassifiedTool} can flag `edited_by_user` in its execution ledger
 * row (owner decision #4's audit requirement).
 *
 * Accepted per-id shapes, keyed by tool-call id:
 * - `true` / `'approve'`                          → approve as proposed
 * - `false` / `'reject'` / `{action: 'reject', reason?}` → reject
 * - `{action: 'edit', arguments: {...}}`          → approve with the user's
 *   edited arguments — the same schema-validated tool path executes them,
 *   so an edit can never bypass what a hand-built form couldn't.
 *
 * EDITS MUST BE GUARDED. An edit's arguments come from the browser, so the
 * card's `editable` / `readonly` field flags are advisory there. Pass
 * {@see ApprovalCards::editGuard()} as the second argument and every edit is
 * reconciled against the SERVER's pending call before a `Decision::edit`
 * exists at all — the guard cannot be forgotten downstream because this is
 * the only path from client input to `Decisions`:
 *
 *     $pending = (new StoredApprovals)->pending($conversationId);
 *
 *     $decisions = ResumeDecisions::fromClient(
 *         $request->validated('decisions'),
 *         $cards->editGuard($pending),
 *     );
 *
 *     $agent->continue($decisions);   // guarded arguments only
 */
final class ResumeDecisions
{
    private const EDITED_KEY_PREFIX = 'ai-kit.approvals.edited.';

    /**
     * @param  array<string, array{action: string, arguments?: array<string, mixed>, reason?: string}|string|bool>  $input
     * @param  (Closure(string, array<string, mixed>): array<string, mixed>)|null  $guard  {@see ApprovalCards::editGuard()}
     */
    public static function fromClient(array $input, ?Closure $guard = null): Decisions
    {
        $map = [];

        foreach ($input as $id => $raw) {
            $map[$id] = self::decision((string) $id, $raw, $guard);
        }

        return Decisions::from($map);
    }

    /**
     * Whether the given tool call's arguments were edited by the user in
     * this request — read by the executing tool for its audit trail.
     */
    public static function wasEdited(string $toolCallId): bool
    {
        return Context::get(self::EDITED_KEY_PREFIX.$toolCallId) === true;
    }

    /**
     * @param  (Closure(string, array<string, mixed>): array<string, mixed>)|null  $guard
     */
    private static function decision(string $id, mixed $raw, ?Closure $guard): Decision
    {
        if (is_bool($raw)) {
            return $raw ? Decision::approve() : Decision::reject();
        }

        if (is_string($raw)) {
            return match ($raw) {
                'approve' => Decision::approve(),
                'reject' => Decision::reject(),
                default => throw new InvalidArgumentException("Unknown approval decision [{$raw}] for tool call [{$id}]."),
            };
        }

        if (is_array($raw)) {
            return match ($raw['action'] ?? null) {
                'approve' => Decision::approve(),
                'reject' => Decision::reject(isset($raw['reason']) ? (string) $raw['reason'] : null),
                'edit' => self::edited($id, $raw['arguments'] ?? null, $guard),
                default => throw new InvalidArgumentException("Unknown approval decision for tool call [{$id}]."),
            };
        }

        throw new InvalidArgumentException("Malformed approval decision for tool call [{$id}].");
    }

    /**
     * @param  array<string, mixed>|null  $arguments
     * @param  (Closure(string, array<string, mixed>): array<string, mixed>)|null  $guard
     */
    private static function edited(string $id, ?array $arguments, ?Closure $guard): Decision
    {
        if (! is_array($arguments) || $arguments === []) {
            throw new InvalidArgumentException("An edit decision for tool call [{$id}] requires non-empty arguments.");
        }

        Context::add(self::EDITED_KEY_PREFIX.$id, true);

        // The guard replaces the client's arguments with the reconciled set,
        // so what reaches Decision::edit is never the raw browser payload.
        return Decision::edit($guard === null ? $arguments : $guard($id, $arguments));
    }
}

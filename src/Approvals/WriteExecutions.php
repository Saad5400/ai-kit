<?php

namespace Saad\AiKit\Approvals;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The exactly-once helper over the {@see WriteExecution} ledger. A confirm
 * turn re-dispatched — a double-click or a queue retry — re-enters with the
 * SAME turn id and per-step sequence; the (turn_id, sequence) unique key
 * makes the second insert fail, so the first result is returned untouched
 * and the write happens exactly once.
 *
 * Two ways in:
 * - {@see self::claim()} inside YOUR OWN transaction, right next to the
 *   write, so the ledger row and the write commit atomically.
 * - {@see self::execute()} when the kit should own the transaction: replay
 *   check → transaction(write + claim) → unique-violation fallback to the
 *   committed first result.
 */
class WriteExecutions
{
    /**
     * The already-committed execution for this (turn, step), or null.
     */
    public function find(string $turnId, int $sequence): ?WriteExecution
    {
        return WriteExecution::query()
            ->where('turn_id', $turnId)
            ->where('sequence', $sequence)
            ->first();
    }

    /**
     * Insert the ledger row — call this INSIDE the same transaction as the
     * write itself, so a colliding re-dispatch fails the unique key here and
     * rolls the whole step back (the first commit stands). A collision
     * surfaces as a QueryException; test it with
     * {@see self::isUniqueViolation()}.
     *
     * @param  array<string, mixed>|null  $result  the write's outcome, for replay/audit
     */
    public function claim(
        string $turnId,
        int $sequence,
        string $actionType,
        ?string $executedBy = null,
        ?array $result = null,
        bool $undoable = false,
    ): WriteExecution {
        return WriteExecution::query()->create([
            'turn_id' => $turnId,
            'sequence' => $sequence,
            'action_type' => $actionType,
            'executed_by' => $executedBy,
            'result' => $result,
            'undoable' => $undoable,
        ]);
    }

    /**
     * Run $write exactly once for this (turn, step): a replay returns the
     * first committed result WITHOUT invoking $write; otherwise the write
     * and its ledger row commit in one transaction, and a concurrent
     * re-dispatch that loses the unique-key race falls back to the winner's
     * result.
     *
     * @param  Closure(): (array<string, mixed>|null)  $write
     * @return array{replayed: bool, result: array<string, mixed>|null, execution: WriteExecution}
     */
    public function execute(
        string $turnId,
        int $sequence,
        string $actionType,
        ?string $executedBy,
        Closure $write,
        bool $undoable = false,
    ): array {
        if (($existing = $this->find($turnId, $sequence)) !== null) {
            return ['replayed' => true, 'result' => $existing->result, 'execution' => $existing];
        }

        try {
            $execution = DB::transaction(function () use ($turnId, $sequence, $actionType, $executedBy, $write, $undoable): WriteExecution {
                $result = $write();

                return $this->claim($turnId, $sequence, $actionType, $executedBy, $result, $undoable);
            });
        } catch (QueryException $exception) {
            if (self::isUniqueViolation($exception) && ($existing = $this->find($turnId, $sequence)) !== null) {
                return ['replayed' => true, 'result' => $existing->result, 'execution' => $existing];
            }

            throw $exception;
        }

        return ['replayed' => false, 'result' => $execution->result, 'execution' => $execution];
    }

    /**
     * Whether a QueryException is a unique-key violation on any driver the
     * kit supports (23000 covers SQLite/MySQL, 23505 Postgres).
     */
    public static function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}

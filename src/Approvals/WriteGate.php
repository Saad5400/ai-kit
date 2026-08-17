<?php

namespace Saad\AiKit\Approvals;

use Saad\AiKit\Approvals\Exceptions\WriteRefusedException;

/**
 * The code-enforced switch deciding how the assistant's write tools behave
 * for the current turn. Container-scoped (bound `scoped()`, so Octane resets
 * it per request/job): the agent and every tool it runs read the SAME mode,
 * and no mode leaks across turns on a reused worker.
 *
 * The default mode is {@see WriteGateMode::Immediate}; a turn opts into
 * Propose or Execute at runtime only — never via the prompt or a tool
 * schema. In Execute mode, {@see self::guard()} enforces the deviation
 * policy the apps converged on: a destructive write must MATCH one of the
 * approved plan's destructive steps — same type, same target, and once per
 * approved step — and every write must stay inside the approved scope.
 * In-scope NON-destructive deviation is allowed on purpose — a real
 * mid-plan error may legitimately need a small extra write; a new deletion
 * or a jump outside the scope is a new request.
 *
 * Matching per STEP rather than per TYPE is what keeps an approval honest:
 * approving "delete widget 7" authorizes deleting widget 7, exactly once —
 * not widget 8, and not widget 7 again.
 */
class WriteGate
{
    protected WriteGateMode $mode = WriteGateMode::Immediate;

    protected ?string $turnId = null;

    /** @var array<string, int|string> The blast radius of the approved plan. */
    protected array $scope = [];

    /**
     * The approved plan's destructive steps that no write has claimed yet.
     * Keys are the original step positions; entries are unset as they are
     * consumed, so one approved deletion authorizes exactly one delete.
     *
     * @var array<int, ProposedWrite>
     */
    protected array $approvedDestructiveSteps = [];

    /**
     * The same-turn draft handles the approved plan minted ("new_widget_1").
     * A step input holding one of these is a placeholder the app resolves to
     * a real id at execute time, so that key is skipped when matching.
     *
     * @var list<string>
     */
    protected array $draftHandles = [];

    /** Monotonic per-turn counter stamped onto each executed write's idempotency key. */
    protected int $sequence = 0;

    public function __construct(protected readonly ProposalBag $bag) {}

    /**
     * Enter Propose mode (the in-app planning turn): {@see self::propose()}
     * collects writes for the plan card and nothing executes.
     */
    public function enterPropose(?string $turnId = null): void
    {
        $this->reset();

        $this->mode = WriteGateMode::Propose;
        $this->turnId = $turnId;
    }

    /**
     * Enter Execute mode (the confirm turn), carrying the turn id (the
     * idempotency-key prefix) and the approved plan — its scope becomes the
     * blast radius {@see self::guard()} enforces, and its destructive steps
     * become the only destructive writes allowed to run.
     */
    public function enterExecute(string $turnId, Plan $plan): void
    {
        $this->reset();

        $this->mode = WriteGateMode::Execute;
        $this->turnId = $turnId;
        $this->scope = $plan->scope;

        foreach ($plan->steps as $step) {
            if ($step->draftRef !== null) {
                $this->draftHandles[] = $step->draftRef;
            }

            if ($step->destructive) {
                $this->approvedDestructiveSteps[] = $step;
            }
        }
    }

    /**
     * Back to the container default (Immediate) — clears everything.
     */
    public function reset(): void
    {
        $this->mode = WriteGateMode::Immediate;
        $this->turnId = null;
        $this->scope = [];
        $this->approvedDestructiveSteps = [];
        $this->draftHandles = [];
        $this->sequence = 0;
        $this->bag->flush();
    }

    public function mode(): WriteGateMode
    {
        return $this->mode;
    }

    public function inImmediateMode(): bool
    {
        return $this->mode === WriteGateMode::Immediate;
    }

    public function inProposeMode(): bool
    {
        return $this->mode === WriteGateMode::Propose;
    }

    public function inExecuteMode(): bool
    {
        return $this->mode === WriteGateMode::Execute;
    }

    public function turnId(): ?string
    {
        return $this->turnId;
    }

    /**
     * @return array<string, int|string>
     */
    public function scope(): array
    {
        return $this->scope;
    }

    /**
     * The sequence number for the next write executed this turn (the
     * idempotency ledger's second key component).
     */
    public function nextSequence(): int
    {
        return $this->sequence++;
    }

    /**
     * Collect one write into the shared bag (risk flags derived from the
     * registered action) — the tool-facing propose path in Propose and
     * Immediate modes alike.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $preview
     */
    public function propose(
        string $type,
        string $title,
        array $input,
        array $preview = [],
        bool $createsRecord = false,
        mixed $actor = null,
    ): ProposedWrite {
        return $this->bag->propose($type, $title, $input, $preview, $createsRecord, $actor);
    }

    public function bag(): ProposalBag
    {
        return $this->bag;
    }

    /**
     * The execute-turn deviation guard. Outside Execute mode it is a no-op.
     * In Execute mode it throws — with a tool-error message the app relays
     * to the model — when the write is destructive but matches no unconsumed
     * approved plan step, or when it targets an id outside the approved
     * scope. A destructive write that passes both checks CONSUMES the step
     * it matched.
     *
     * @throws WriteRefusedException
     */
    public function guard(ProposedWrite $write): void
    {
        if ($this->mode !== WriteGateMode::Execute) {
            return;
        }

        $matched = null;

        if ($write->destructive) {
            $matched = $this->matchApprovedStep($write);

            if ($matched === null) {
                throw new WriteRefusedException(
                    $write,
                    WriteRefusedException::REASON_OUT_OF_PLAN,
                    'Refused: "'.$write->title.'" deletes records but was not one of the approved plan steps '
                    .'(or that step was already carried out). Do not perform deletions the user did not approve — '
                    .'if it is genuinely needed, stop and let them approve it as a new request.',
                );
            }
        }

        if ($this->scope !== [] && $this->outOfScope($write)) {
            throw new WriteRefusedException(
                $write,
                WriteRefusedException::REASON_OUT_OF_SCOPE,
                'Refused: "'.$write->title.'" targets something outside the approved plan\'s scope. '
                .'Stay within what the plan was approved for; a change elsewhere is a new request.',
            );
        }

        // Only consume once the write is cleared to run, so a refusal never
        // burns an approval the user is still owed.
        if ($matched !== null) {
            unset($this->approvedDestructiveSteps[$matched]);
        }
    }

    /**
     * The position of the first unconsumed approved destructive step this
     * write satisfies, or null. A step is satisfied when the types are equal
     * AND the write's input agrees with the approved input everywhere the
     * two overlap — the approved keys the write does not mention are not
     * compared, so an app addressing the same record differently at execute
     * time is not blocked, while contradicting an approved id is.
     */
    protected function matchApprovedStep(ProposedWrite $write): ?int
    {
        foreach ($this->approvedDestructiveSteps as $index => $step) {
            if ($step->type === $write->type && $this->inputMatches($step->input, $write->input)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether an executed input contradicts the approved one. Only scalar
     * keys present in BOTH are compared (loosely, so 7 and "7" agree), and
     * an approved value that is one of the plan's draft handles is skipped:
     * it stood for a record that did not exist yet at approval time.
     *
     * @param  array<string, mixed>  $approved
     * @param  array<string, mixed>  $actual
     */
    protected function inputMatches(array $approved, array $actual): bool
    {
        foreach ($approved as $key => $value) {
            if (! is_scalar($value) || ! array_key_exists($key, $actual)) {
                continue;
            }

            if (in_array((string) $value, $this->draftHandles, true)) {
                continue;
            }

            if (! is_scalar($actual[$key]) || (string) $actual[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the write references an id that conflicts with the declared
     * scope. Only like-for-like keys are compared, and an input that names
     * none of the scoped keys is treated as in-scope (permissive, so a
     * legitimate in-scope adaptation is never blocked over an unmatchable
     * id).
     */
    protected function outOfScope(ProposedWrite $write): bool
    {
        foreach ($this->scope as $key => $scopedId) {
            foreach ($this->collectValues($write->input, (string) $key) as $value) {
                if ((string) $value !== (string) $scopedId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively collect every scalar stored under $key anywhere in a
     * (possibly nested) input payload. Only null and the empty string are
     * skipped as "no id given" — 0 and "0" are real values, and dropping
     * them would hand the model a way around the scope guard.
     *
     * @param  array<mixed>  $input
     * @return list<int|string>
     */
    protected function collectValues(array $input, string $key): array
    {
        $values = [];

        foreach ($input as $index => $value) {
            if ((string) $index === $key && is_scalar($value) && (string) $value !== '') {
                $values[] = is_int($value) ? $value : (string) $value;
            } elseif (is_array($value)) {
                $values = [...$values, ...$this->collectValues($value, $key)];
            }
        }

        return $values;
    }
}

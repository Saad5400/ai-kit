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
 * policy the apps converged on: a destructive write must be one of the
 * approved plan's steps, and every write must stay inside the approved
 * scope. In-scope NON-destructive deviation is allowed on purpose — a real
 * mid-plan error may legitimately need a small extra write; a new deletion
 * or a jump outside the scope is a new request.
 */
class WriteGate
{
    protected WriteGateMode $mode = WriteGateMode::Immediate;

    protected ?string $turnId = null;

    /** @var array<string, int|string> The blast radius of the approved plan. */
    protected array $scope = [];

    /** @var list<string> The action types of the approved plan's destructive steps. */
    protected array $approvedDestructiveTypes = [];

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
     * become the only destructive types allowed to run.
     */
    public function enterExecute(string $turnId, Plan $plan): void
    {
        $this->reset();

        $this->mode = WriteGateMode::Execute;
        $this->turnId = $turnId;
        $this->scope = $plan->scope;

        foreach ($plan->steps as $step) {
            if ($step->destructive) {
                $this->approvedDestructiveTypes[] = $step->type;
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
        $this->approvedDestructiveTypes = [];
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
     * to the model — when the write is destructive but was not one of the
     * approved plan's steps, or when it targets an id outside the approved
     * scope.
     *
     * @throws WriteRefusedException
     */
    public function guard(ProposedWrite $write): void
    {
        if ($this->mode !== WriteGateMode::Execute) {
            return;
        }

        if ($write->destructive && ! in_array($write->type, $this->approvedDestructiveTypes, true)) {
            throw new WriteRefusedException(
                $write,
                WriteRefusedException::REASON_OUT_OF_PLAN,
                'Refused: "'.$write->title.'" deletes records but was not one of the approved plan steps. '
                .'Do not perform deletions the user did not approve — if it is genuinely needed, stop and let '
                .'them approve it as a new request.',
            );
        }

        if ($this->scope !== [] && $this->outOfScope($write)) {
            throw new WriteRefusedException(
                $write,
                WriteRefusedException::REASON_OUT_OF_SCOPE,
                'Refused: "'.$write->title.'" targets something outside the approved plan\'s scope. '
                .'Stay within what the plan was approved for; a change elsewhere is a new request.',
            );
        }
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
     * Recursively collect every non-empty scalar stored under $key anywhere
     * in a (possibly nested) input payload.
     *
     * @param  array<mixed>  $input
     * @return list<int|string>
     */
    protected function collectValues(array $input, string $key): array
    {
        $values = [];

        foreach ($input as $index => $value) {
            if ((string) $index === $key && is_scalar($value) && (string) $value !== '' && (string) $value !== '0') {
                $values[] = is_int($value) ? $value : (string) $value;
            } elseif (is_array($value)) {
                $values = [...$values, ...$this->collectValues($value, $key)];
            }
        }

        return $values;
    }
}

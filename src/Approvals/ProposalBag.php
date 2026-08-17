<?php

namespace Saad\AiKit\Approvals;

use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;

/**
 * Collects the {@see ProposedWrite}s the write tools build during one turn.
 * Container-scoped (bound `scoped()` so Octane resets it per request/job):
 * the agent and every tool it runs share ONE bag, and nothing leaks across
 * turns on a reused worker.
 *
 * Risk flags are derived here from the registered action — tools hand over
 * (type, title, input, preview) and never a destructive flag. An exact
 * duplicate (same type + order-insensitive input) is ignored, so a retried
 * tool batch cannot stack the same write twice. A create is minted a
 * readable same-turn draft handle ("new_{type}_1") the moment it is
 * registered, so a child proposed later in the same reply can reference the
 * not-yet-persisted parent.
 */
class ProposalBag
{
    /** @var list<ProposedWrite> */
    protected array $writes = [];

    /** Monotonic per-turn counter behind the same-turn draft handles. */
    protected int $refCounter = 0;

    public function __construct(protected readonly ActionRegistry $registry) {}

    /**
     * Build and collect one write, deriving its risk flags from the
     * registered action. Returns the collected write (or the earlier
     * duplicate it collapsed onto).
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $preview
     *
     * @throws UnknownActionException when no action is registered for $type
     */
    public function propose(
        string $type,
        string $title,
        array $input,
        array $preview = [],
        bool $createsRecord = false,
        mixed $actor = null,
    ): ProposedWrite {
        foreach ($this->writes as $existing) {
            if ($existing->type === $type && $this->signature($existing->input) === $this->signature($input)) {
                return $existing;
            }
        }

        $write = ProposedWrite::derive($this->registry, $type, $title, $input, $preview, $createsRecord, $actor);

        if ($write->createsRecord) {
            $write->draftRef = 'new_'.$type.'_'.(++$this->refCounter);
        }

        $this->writes[] = $write;

        return $write;
    }

    /**
     * The action type registered this turn under the given draft handle, or
     * null — lets a write tool tell a same-turn draft parent reference apart
     * from a real id.
     */
    public function draftType(string $ref): ?string
    {
        $ref = trim($ref);

        foreach ($this->writes as $write) {
            if ($write->draftRef !== null && $write->draftRef === $ref) {
                return $write->type;
            }
        }

        return null;
    }

    /**
     * @return list<ProposedWrite>
     */
    public function all(): array
    {
        return $this->writes;
    }

    public function isEmpty(): bool
    {
        return $this->writes === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->writes !== [];
    }

    public function flush(): void
    {
        $this->writes = [];
        $this->refCounter = 0;
    }

    /**
     * Fold the collected writes into a {@see Plan} for the confirm card.
     *
     * @param  array<string, int|string>  $scope
     */
    public function toPlan(?string $summary = null, array $scope = [], ?string $planId = null): Plan
    {
        $builder = (new PlanBuilder($this->registry))
            ->summary($summary ?? '')
            ->scope($scope);

        foreach ($this->writes as $write) {
            $builder->add($write);
        }

        return $builder->build($planId);
    }

    /**
     * A stable, order-insensitive signature of an input payload so two
     * payloads that differ only in key order compare equal.
     *
     * @param  array<string, mixed>  $input
     */
    protected function signature(array $input): string
    {
        $this->ksortRecursive($input);

        return (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<mixed>  $value
     */
    protected function ksortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }

        unset($item);

        ksort($value);
    }
}

<?php

namespace Saad\AiKit\Approvals;

/**
 * The plan the assistant proposes in a planning turn — the canonical shape
 * unifying catodemy's `ProposalBag::toPlan()` card and s-grade's
 * `PlanDraft::toEntry()`:
 *
 *   { id, summary, steps: [{ id, type, title, preview: string[],
 *     destructive, typed_confirm? }], scope, destructive, auto_approve, status }
 *
 * `destructive` is DERIVED from the steps (never a stored input), and each
 * step's own flags were derived from the registered action, so the confirm
 * rung can never be talked away by the model. `auto_approve` is computed by
 * {@see PlanBuilder}'s predicate at build time and carried verbatim.
 */
final class Plan
{
    /**
     * @param  list<ProposedWrite>  $steps
     * @param  array<string, int|string>  $scope  the blast radius the execute turn is bounded to
     */
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly array $steps,
        public readonly array $scope = [],
        public readonly bool $autoApprove = false,
        public readonly ProposalStatus $status = ProposalStatus::Pending,
    ) {}

    /**
     * Whether any step destroys records — always derived, never stored.
     */
    public function destructive(): bool
    {
        return array_any($this->steps, fn (ProposedWrite $step): bool => $step->destructive);
    }

    /**
     * The retype gate for the whole plan: the first step demanding one wins
     * (a plan realistically carries at most one catastrophic step).
     */
    public function typedConfirm(): ?string
    {
        foreach ($this->steps as $step) {
            if ($step->typedConfirm !== null) {
                return $step->typedConfirm;
            }
        }

        return null;
    }

    public function withStatus(ProposalStatus $status): self
    {
        return new self($this->id, $this->summary, $this->steps, $this->scope, $this->autoApprove, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            summary: (string) ($data['summary'] ?? ''),
            steps: array_map(
                static fn (array $step): ProposedWrite => ProposedWrite::fromArray($step),
                array_values((array) ($data['steps'] ?? [])),
            ),
            scope: (array) ($data['scope'] ?? []),
            autoApprove: (bool) ($data['auto_approve'] ?? false),
            status: ProposalStatus::from((string) ($data['status'] ?? ProposalStatus::Pending->value)),
        );
    }

    /**
     * The full storable form, inputs included (round-trips via
     * {@see self::fromArray()} so the confirm turn executes EXACTLY what was
     * previewed).
     *
     * @return array{id: string, summary: string, steps: list<array<string, mixed>>, scope: array<string, int|string>, auto_approve: bool, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->summary,
            'steps' => array_map(static fn (ProposedWrite $step): array => $step->toArray(), $this->steps),
            'scope' => $this->scope,
            'auto_approve' => $this->autoApprove,
            'status' => $this->status->value,
        ];
    }

    /**
     * The canonical client payload (the plan card / SSE `plan` event shape).
     * Step inputs are withheld — the client sees titles and previews, the
     * server keeps the executable payloads.
     *
     * @return array{id: string, summary: string, steps: list<array<string, mixed>>, scope: array<string, int|string>, destructive: bool, auto_approve: bool, status: string, typed_confirm?: string}
     */
    public function toClientPayload(): array
    {
        $payload = [
            'id' => $this->id,
            'summary' => $this->summary,
            'steps' => array_map(static fn (ProposedWrite $step): array => $step->toStep(), $this->steps),
            'scope' => $this->scope,
            'destructive' => $this->destructive(),
            'auto_approve' => $this->autoApprove,
            'status' => $this->status->value,
        ];

        if (($typedConfirm = $this->typedConfirm()) !== null) {
            $payload['typed_confirm'] = $typedConfirm;
        }

        return $payload;
    }
}

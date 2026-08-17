<?php

namespace Saad\AiKit\Testing;

use Illuminate\Database\Eloquent\Factories\Factory;
use Saad\AiKit\Approvals\Proposal;
use Saad\AiKit\Approvals\ProposalStatus;

/**
 * Factory for the kit's {@see Proposal} — lives in the Testing namespace
 * (not database/factories) because /tests is export-ignored and consuming
 * apps need it too. The default row is a well-formed PENDING proposal whose
 * payload carries the four keys the executor writes (action, category,
 * input, preview), so trailer/card code paths behave exactly as on a real
 * proposed row.
 *
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'update_widget',
            'category' => 'widgets',
            'payload' => [
                'action' => 'update_widget',
                'category' => 'widgets',
                'input' => ['name' => 'A'],
                'preview' => ['name' => 'A'],
            ],
            'summary' => 'Update widget A',
            'status' => ProposalStatus::Pending,
            'proposed_by' => '1',
        ];
    }

    public function proposedBy(string $proposedBy): static
    {
        return $this->state(['proposed_by' => $proposedBy]);
    }

    public function confirmed(): static
    {
        return $this->state([
            'status' => ProposalStatus::Confirmed,
            'executed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ProposalStatus::Rejected]);
    }

    public function failed(string $error = 'Execution failed.'): static
    {
        return $this->state([
            'status' => ProposalStatus::Failed,
            'error' => $error,
            'executed_at' => now(),
        ]);
    }
}

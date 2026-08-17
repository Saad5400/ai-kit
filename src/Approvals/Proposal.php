<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change the assistant PROPOSED but never applied itself — the stored
 * half of the two-phase write contract. A propose path persists the row as
 * `pending`; a human confirming runs it through {@see ProposalExecutor}
 * (→ `confirmed`, or `failed` with the error surfaced), and declining marks
 * it `rejected`. ULID keys because the ids travel through model output and
 * the chat client.
 *
 * `proposed_by` is a string so it works for user ids and owner keys like
 * "telegram:{id}" alike — resolving it back to an actor is the app's job.
 *
 * @property string $id
 * @property string $type
 * @property string|null $category
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property ProposalStatus $status
 * @property string $proposed_by
 * @property string|null $error
 * @property Carbon|null $executed_at
 */
class Proposal extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function getTable(): string
    {
        return config('ai-kit.approvals.proposals_table', 'ai_proposals');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => ProposalStatus::class,
            'executed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === ProposalStatus::Pending;
    }

    public function scopeStatus(Builder $query, ProposalStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeProposedBy(Builder $query, string $proposedBy): Builder
    {
        return $query->where('proposed_by', $proposedBy);
    }

    /**
     * The exact shape the chat client renders as an action card (SSE
     * `proposal` events and the rehydration endpoint agree on it — the
     * canonical 7-field flat payload). `category` groups the card visually
     * and `details` are the normalized fields worth showing under the
     * summary.
     *
     * @return array{id: string, type: string, category: string, summary: string, details: array<string, mixed>, status: string, error: string|null}
     */
    public function toClientPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => (string) ($this->category ?? $this->payload['category'] ?? 'system'),
            'summary' => $this->summary,
            'details' => is_array($this->payload['preview'] ?? null) ? $this->payload['preview'] : [],
            'status' => $this->status->value,
            'error' => $this->error,
        ];
    }
}

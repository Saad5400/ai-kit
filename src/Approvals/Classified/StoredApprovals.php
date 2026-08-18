<?php

namespace Saad\AiKit\Approvals\Classified;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Saad\AiKit\Conversations\ConversationContent;

/**
 * Read seam for a conversation's STILL-PENDING approvals, reconstructed from
 * the stored pause markers — what a client needs to repaint its approval /
 * question cards after a page reload, before any resume request exists.
 *
 * The vendor store stamps each paused assistant row with
 * `approval_state = {"pending": {toolCallId: reason}}` and removes ids from
 * the map as decisions resolve, so whatever remains pending IS the set of
 * undecided calls. Tool name and arguments come from the same row's
 * `tool_calls` trace — which is why persisted tool traces are a prerequisite
 * for the classified seam. Both columns decrypt through
 * {@see ConversationContent::reveal()}, so plaintext (pre-encryption) rows
 * read the same way.
 *
 * Feed the result to {@see ApprovalCards::cards()} for the wire payloads.
 */
class StoredApprovals
{
    public function __construct(protected ?string $connection = null) {}

    /**
     * @return Collection<int, PendingApproval>
     */
    public function pending(string $conversationId): Collection
    {
        return DB::connection($this->connection)
            ->table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('approval_state')
            ->orderBy('id')
            ->get()
            ->flatMap(function (object $row): Collection {
                $state = json_decode(ConversationContent::reveal($row->approval_state) ?? 'null', true);
                $pending = is_array($state) && is_array($state['pending'] ?? null) ? $state['pending'] : [];

                if ($pending === []) {
                    return collect();
                }

                $calls = collect(json_decode(ConversationContent::reveal($row->tool_calls) ?? '[]', true) ?: [])
                    ->keyBy('id');

                return collect($pending)
                    ->map(fn (mixed $reason, string $id): PendingApproval => new PendingApproval(
                        $id,
                        (string) ($calls[$id]['name'] ?? ''),
                        (array) ($calls[$id]['arguments'] ?? []),
                        is_string($reason) && $reason !== '' ? $reason : null,
                    ))
                    ->values();
            })
            ->values();
    }
}

<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Facades\DB;

/**
 * Answers "does this participant own this conversation?" — the guard every
 * app hand-rolls before serving history or accepting a follow-up turn.
 *
 * Centralized because the hand-rolled versions share the same defect:
 * filtering `participant_id` alone. Participant ids are only unique per
 * type (a session id, "telegram:{chatId}", "admin:{id}", a user key can
 * collide across owner kinds), so pass the participant type whenever the
 * app has one and BOTH columns are checked. Passing null matches any type,
 * for genuinely type-less owner keys.
 */
class ConversationOwnership
{
    public function __construct(protected ?string $connection = null)
    {
        //
    }

    /**
     * Determine whether the given participant owns the conversation.
     */
    public function owns(string $conversationId, string $participantId, ?string $participantType = null): bool
    {
        return DB::connection($this->connection)
            ->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->where('participant_id', $participantId)
            ->when(
                $participantType !== null,
                fn ($query) => $query->where('participant_type', $participantType),
            )
            ->exists();
    }

    /**
     * Resolve the conversations table name from the vendor config keys.
     */
    protected function conversationsTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }
}

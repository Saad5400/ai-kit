<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;

/**
 * Answers "does this participant own this conversation?" — the guard every
 * app runs before serving history or accepting a follow-up turn.
 *
 * @deprecated laravel/ai 1.0 ships this check on the store: call
 *             `app(ConversationStore::class)->conversationBelongsTo($conversationId, $participantType, $participantId)`
 *             (Laravel\Ai\Contracts\VerifiesConversationOwnership). This
 *             alias stays for one release and delegates there whenever a
 *             participant type is given.
 *
 * Participant ids are only unique per type (a session id, "telegram:{chatId}",
 * "admin:{id}", a user key can collide across owner kinds), so pass the
 * participant type whenever the app has one and BOTH columns are checked.
 * Passing null keeps this class's legacy meaning — match ANY type — which
 * conversationBelongsTo() does not offer (there, a null type means "an
 * ownerless conversation"); migrate those call sites deliberately.
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
        $store = app(ConversationStore::class);

        if ($participantType !== null && $store instanceof VerifiesConversationOwnership) {
            return $store->conversationBelongsTo($conversationId, $participantType, $participantId);
        }

        return DB::connection($this->connection)
            ->table(config('ai.conversations.tables.conversations', 'agent_conversations'))
            ->where('id', $conversationId)
            ->where('participant_id', $participantId)
            ->when(
                $participantType !== null,
                fn ($query) => $query->where('participant_type', $participantType),
            )
            ->exists();
    }
}

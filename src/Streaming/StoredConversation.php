<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Throwable;

/**
 * The conversation a turn that did NOT complete was stored in — what the
 * failure path hands the client so its next message (or a Retry) continues
 * that thread instead of opening a duplicate.
 *
 * A completed turn's id reaches the app through the vendor's `then()`; a
 * failed or stopped one never runs it. laravel/ai 1.0 names a NEW
 * conversation up front, though (RememberConversation puts the pending id
 * on the StreamableAgentResponse before the first event), and its failure
 * path stores the turn under that id. This reads it back — but only once
 * the conversation row exists, because a turn the vendor did not store (a
 * failover retry's abandoned attempt, a turn with nothing to remember and
 * InterruptedTurns off) leaves an id that names nothing, and continuing it
 * would write messages into a conversation that was never created.
 *
 * Null when the stream is not a remembering StreamableAgentResponse, or
 * when nothing was stored. A continued turn names the conversation it
 * continued, which the app already knows.
 */
final class StoredConversation
{
    /**
     * @param  iterable<mixed>|null  $stream
     */
    public static function idOf(?iterable $stream): ?string
    {
        if (! $stream instanceof StreamableAgentResponse || ! is_string($id = $stream->conversationId) || $id === '') {
            return null;
        }

        return self::exists($id) ? $id : null;
    }

    /**
     * Whether a conversation row exists. Stores that are not the database
     * store (or a subclass, like the kit's encrypted store) cannot be asked,
     * so their id is trusted.
     */
    protected static function exists(string $id): bool
    {
        $container = Container::getInstance();

        if (! $container->bound(ConversationStore::class) || ! $container->make(ConversationStore::class) instanceof DatabaseConversationStore) {
            return true;
        }

        try {
            return DB::connection(config('ai.conversations.connection'))
                ->table(config('ai.conversations.tables.conversations', 'agent_conversations'))
                ->where('id', $id)
                ->exists();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

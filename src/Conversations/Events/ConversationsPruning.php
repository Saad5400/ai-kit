<?php

namespace Saad\AiKit\Conversations\Events;

use Carbon\CarbonInterface;

/**
 * Fired by ai-kit:prune-conversations BEFORE the stale conversations and
 * their messages are deleted, so apps can cascade their own per-conversation
 * resources (chat attachments and their stored files, caches, …) while the
 * ids still resolve.
 *
 * One event per id-ordered chunk, so a listener sees a bounded batch rather
 * than a whole mature table. The ids are the chunk's CANDIDATES: the command
 * re-applies the retention cutoff when it deletes, so a conversation revived
 * in the same instant is spared and a listener may — very rarely — have
 * cascaded for a thread that survives. Treat the event as best-effort and
 * re-check `updated_at` if that matters to you.
 */
class ConversationsPruning
{
    /**
     * @param  list<string>  $conversationIds  Ids of the conversations about to be deleted.
     * @param  CarbonInterface  $cutoff  Conversations idle since before this moment are pruned.
     */
    public function __construct(
        public array $conversationIds,
        public CarbonInterface $cutoff,
    ) {
        //
    }
}

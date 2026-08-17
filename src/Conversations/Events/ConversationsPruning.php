<?php

namespace Saad\AiKit\Conversations\Events;

use Carbon\CarbonInterface;

/**
 * Fired by ai-kit:prune-conversations BEFORE the stale conversations and
 * their messages are deleted, so apps can cascade their own per-conversation
 * resources (chat attachments and their stored files, caches, …) while the
 * ids still resolve.
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

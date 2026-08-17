<?php

namespace Saad\AiKit\Conversations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Saad\AiKit\Conversations\Events\ConversationsPruning;

/**
 * Deletes conversations (and their messages) idle longer than the retention
 * window. Anonymous-participant apps have no other way to reach old threads,
 * so this keeps the tables from growing forever; schedule it daily.
 *
 * A ConversationsPruning event fires with the doomed ids BEFORE anything is
 * deleted — listen to it to cascade app-owned resources (chat attachments
 * and their stored files, per-conversation caches, …).
 */
class PruneConversationsCommand extends Command
{
    protected $signature = 'ai-kit:prune-conversations
        {--days= : Prune conversations idle for more than this many days (default: ai-kit.conversations.retention_days)}';

    protected $description = 'Delete AI conversations and their messages idle longer than the retention window';

    public function handle(): int
    {
        $days = max(1, $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('ai-kit.conversations.retention_days', 30));

        $cutoff = now()->subDays($days);

        $connection = DB::connection(config('ai.conversations.connection'));
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $conversationIds = $connection->table($conversationsTable)
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($conversationIds === []) {
            $this->info(sprintf('No conversations idle for more than %d days.', $days));

            return self::SUCCESS;
        }

        Event::dispatch(new ConversationsPruning($conversationIds, $cutoff));

        $messagesDeleted = 0;

        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $messagesDeleted += $connection->table($messagesTable)
                ->whereIn('conversation_id', $chunk)
                ->delete();

            $connection->table($conversationsTable)
                ->whereIn('id', $chunk)
                ->delete();
        }

        $this->info(sprintf(
            'Pruned %d conversations (%d messages) idle for more than %d days.',
            count($conversationIds),
            $messagesDeleted,
            $days,
        ));

        return self::SUCCESS;
    }
}

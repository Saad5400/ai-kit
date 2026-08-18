<?php

namespace Saad\AiKit\Conversations\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Saad\AiKit\Conversations\Events\ConversationsPruning;

/**
 * Deletes conversations (and their messages) idle longer than the retention
 * window. Retention is forever by default — without a configured window or
 * an explicit --days the command warns and touches nothing. Apps whose
 * anonymous participants have no other way to reach old threads opt into a
 * window and schedule this daily.
 *
 * Work happens in id-ordered chunks — a mature table never lands in memory
 * at once — and the retention cutoff is RE-APPLIED to every delete. A
 * conversation revived between being read and being deleted therefore
 * survives with its messages intact: the run only ever deletes the messages
 * of conversations whose rows it actually removed.
 *
 * A ConversationsPruning event fires per chunk with the doomed ids BEFORE
 * anything in that chunk is deleted — listen to it to cascade app-owned
 * resources (chat attachments and their stored files, per-conversation
 * caches, …).
 */
class PruneConversationsCommand extends Command
{
    protected $signature = 'ai-kit:prune-conversations
        {--days= : Prune conversations idle for more than this many days (default: ai-kit.conversations.retention_days; retention is forever when neither is set)}
        {--chunk=500 : How many conversations to read, announce and delete per batch}';

    protected $description = 'Delete AI conversations and their messages idle longer than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('ai-kit.conversations.retention_days');

        if ($days === null) {
            $this->warn('Retention is forever (ai-kit.conversations.retention_days is null and no --days given) — nothing pruned.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $days);
        $chunkSize = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);

        $connection = DB::connection(config('ai.conversations.connection'));
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $conversationsDeleted = 0;
        $messagesDeleted = 0;
        $after = null;

        while (true) {
            $candidates = $connection->table($conversationsTable)
                ->where('updated_at', '<', $cutoff)
                ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            if ($candidates === []) {
                break;
            }

            $after = end($candidates);

            Event::dispatch(new ConversationsPruning($candidates, $cutoff));

            $deleted = $this->deleteChunk($connection, $conversationsTable, $candidates, $cutoff);

            if ($deleted !== []) {
                $conversationsDeleted += count($deleted);
                $messagesDeleted += $connection->table($messagesTable)
                    ->whereIn('conversation_id', $deleted)
                    ->delete();
            }
        }

        if ($conversationsDeleted === 0) {
            $this->info(sprintf('No conversations idle for more than %d days.', $days));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Pruned %d conversations (%d messages) idle for more than %d days.',
            $conversationsDeleted,
            $messagesDeleted,
            $days,
        ));

        return self::SUCCESS;
    }

    /**
     * Delete the chunk's still-idle conversations and report which ids
     * actually went. The cutoff on the DELETE is what spares a revived
     * conversation, and reading back the survivors is what keeps its
     * messages: only the rows this run removed have their messages deleted.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    protected function deleteChunk(
        ConnectionInterface $connection,
        string $table,
        array $candidates,
        CarbonInterface $cutoff,
    ): array {
        $connection->table($table)
            ->whereIn('id', $candidates)
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $survivors = $connection->table($table)
            ->whereIn('id', $candidates)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_values(array_diff($candidates, $survivors));
    }
}

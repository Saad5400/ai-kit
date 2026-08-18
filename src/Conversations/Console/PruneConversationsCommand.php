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
 * window, and strips tool traces older than the SEPARATE trace-retention
 * window (owner decision #7: traces are short-lived even when conversations
 * are kept forever). Conversation retention is forever by default — without
 * a configured window or an explicit --days the delete pass warns and
 * touches nothing; the trace pass runs whenever `trace_retention_days` (or
 * --trace-days) is set. Apps schedule this daily.
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
        {--trace-days= : Strip tool traces from messages older than this many days (default: ai-kit.conversations.trace_retention_days)}
        {--chunk=500 : How many conversations to read, announce and delete per batch}';

    protected $description = 'Delete AI conversations and their messages idle longer than the retention window, and strip tool traces past the trace window';

    public function handle(): int
    {
        $status = $this->pruneConversations();

        $this->pruneToolTraces();

        return $status;
    }

    protected function pruneConversations(): int
    {
        $days = $this->option('days') ?? config('ai-kit.conversations.retention_days');

        if ($days === null) {
            $this->warn('Retention is forever (ai-kit.conversations.retention_days is null and no --days given) — no conversations pruned.');

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
     * Strip tool traces (attachments, tool calls/results, meta, the pause
     * marker) from message rows older than the trace window. Usage stays —
     * aggregate numbers, no user content. Runs in id-chunks like the delete
     * pass; a row already stripped matches nothing and is never rewritten.
     */
    protected function pruneToolTraces(): void
    {
        $traceDays = $this->option('trace-days') ?? config('ai-kit.conversations.trace_retention_days');

        if ($traceDays === null) {
            return;
        }

        $cutoff = now()->subDays(max(1, (int) $traceDays));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $connection = DB::connection(config('ai.conversations.connection'));
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $stripped = 0;

        while (true) {
            $ids = $connection->table($messagesTable)
                ->where('created_at', '<', $cutoff)
                ->where(fn ($query) => $query
                    ->where('attachments', '!=', '[]')
                    ->orWhere('tool_calls', '!=', '[]')
                    ->orWhere('tool_results', '!=', '[]')
                    ->orWhere('meta', '!=', '[]')
                    ->orWhereNotNull('approval_state'))
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $stripped += $connection->table($messagesTable)
                ->whereIn('id', $ids)
                ->update([
                    'attachments' => '[]',
                    'tool_calls' => '[]',
                    'tool_results' => '[]',
                    'meta' => '[]',
                    'approval_state' => null,
                ]);
        }

        if ($stripped > 0) {
            $this->info(sprintf(
                'Stripped tool traces from %d messages older than %d days.',
                $stripped,
                max(1, (int) $traceDays),
            ));
        }
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

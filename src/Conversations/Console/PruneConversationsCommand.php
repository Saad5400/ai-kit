<?php

namespace Saad\AiKit\Conversations\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Enums\MessageStatus;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\Events\ConversationsPruning;
use Saad\AiKit\Conversations\StoredSteps;
use Saad\AiKit\Conversations\UndecryptableConversationContent;

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
     * Strip tool traces from message rows older than the trace window: the
     * row keeps the text the user saw (as ONE content-only step) and its
     * usage — aggregate numbers, no user content — while attachments, meta,
     * tool calls with their results, reasoning and replay blocks go. The
     * 0.10 trace columns, still present until the phase-B migration drops
     * them, are emptied too.
     *
     * `steps` is sealed, so the rewrite is per row: reveal, reduce, re-seal
     * the way the bound store writes. The one `paused` row that is still its
     * conversation's newest assistant row is skipped — its calls are what a
     * resume needs, and under laravel/ai 1.0 it is the only pause that can
     * resume. An abandoned pause (a newer assistant row exists) is stripped
     * like any other row; it keeps its `paused` status. Runs in id-chunks like the delete pass.
     *
     * Candidates are rows with anything non-empty in attachments, meta, the
     * legacy columns — or `steps`: a 1.0 row can carry its traces in `steps`
     * alone (meta `'[]'`, e.g. a row converted from a 0.10 row with no
     * meta). Sealed steps cannot be told apart in SQL, so an assistant row
     * with non-empty steps is read and, when its steps carry nothing past
     * the text and every other column is already empty, left untouched — a
     * stripped row is never rewritten, only re-read. A row whose `steps` is
     * still NULL (not converted yet) keeps it NULL for the backfill, and a
     * row whose sealed steps this app key cannot decrypt is left alone
     * entirely (reported, never overwritten with its own ciphertext).
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
        $schema = $connection->getSchemaBuilder();

        $legacy = array_values(array_filter(
            ['tool_calls', 'tool_results'],
            fn (string $column): bool => $schema->hasColumn($messagesTable, $column),
        ));
        $hasApprovalState = $schema->hasColumn($messagesTable, 'approval_state');
        $hasStatus = $schema->hasColumn($messagesTable, 'status');
        $encrypt = ConversationContent::encryptsAtRest();

        $stripped = 0;
        $undecryptable = 0;
        $after = null;

        while (true) {
            $rows = $connection->table($messagesTable)
                ->where('created_at', '<', $cutoff)
                ->when($hasStatus, fn ($query) => $query->where(fn ($query) => $query
                    ->where('status', '!=', MessageStatus::Paused->value)
                    // An abandoned pause: a newer assistant row exists in its
                    // conversation, and under 1.0 only the newest can resume.
                    ->orWhereExists(fn ($newer) => $newer->selectRaw('1')
                        ->from($messagesTable.' as newer')
                        ->whereColumn('newer.conversation_id', $messagesTable.'.conversation_id')
                        ->where('newer.role', 'assistant')
                        ->whereColumn('newer.id', '>', $messagesTable.'.id'))))
                ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
                ->where(function ($query) use ($legacy, $hasApprovalState) {
                    $query->where('attachments', '!=', '[]')->orWhere('meta', '!=', '[]')
                        ->orWhere(fn ($query) => $query->where('role', 'assistant')
                            ->whereNotNull('steps')
                            ->whereNotIn('steps', ['', '[]']));

                    foreach ($legacy as $column) {
                        $query->orWhere($column, '!=', '[]');
                    }

                    if ($hasApprovalState) {
                        $query->orWhereNotNull('approval_state');
                    }
                })
                ->orderBy('id')
                ->limit($chunkSize)
                ->get(['id', 'role', 'content', 'steps', 'attachments', 'meta', ...$legacy, ...($hasApprovalState ? ['approval_state'] : [])]);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                try {
                    $decoded = $row->steps === null ? null : ConversationContent::revealJsonStrict($row->steps);
                } catch (UndecryptableConversationContent) {
                    $undecryptable++;

                    continue;
                }

                if ($decoded !== null && ! StoredSteps::carriesTraces($decoded) && $this->carriesNothingElse($row, $legacy, $hasApprovalState)) {
                    continue;
                }

                $steps = match (true) {
                    $decoded === null => null,
                    $row->role !== 'assistant' => '[]',
                    default => json_encode(StoredSteps::contentOnly(
                        $decoded,
                        ConversationContent::reveal((string) $row->content),
                    )),
                };

                $stripped += $connection->table($messagesTable)
                    ->where('id', $row->id)
                    ->update([
                        'attachments' => '[]',
                        'meta' => '[]',
                        ...($steps === null ? [] : ['steps' => $encrypt ? ConversationContent::concealJson($steps) : $steps]),
                        ...array_fill_keys($legacy, '[]'),
                        ...($hasApprovalState ? ['approval_state' => null] : []),
                    ]);
            }

            $after = $rows->last()->id;
        }

        if ($stripped > 0) {
            $this->info(sprintf(
                'Stripped tool traces from %d messages older than %d days.',
                $stripped,
                max(1, (int) $traceDays),
            ));
        }

        if ($undecryptable > 0) {
            $this->warn(sprintf(
                'Left %d messages untouched: their steps do not decrypt with this app key (restore it via APP_PREVIOUS_KEYS and re-run).',
                $undecryptable,
            ));
        }
    }

    /**
     * Whether a candidate row's non-steps columns hold nothing to strip.
     *
     * @param  list<string>  $legacy
     */
    protected function carriesNothingElse(object $row, array $legacy, bool $hasApprovalState): bool
    {
        foreach (['attachments', 'meta', ...$legacy] as $column) {
            if (! in_array($row->{$column}, [null, '', '[]'], true)) {
                return false;
            }
        }

        return ! $hasApprovalState || $row->approval_state === null;
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

<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;

/**
 * Rewrites laravel/ai 0.10 message rows (`tool_calls` / `tool_results` /
 * `approval_state`) onto 1.0's `steps` / `status`, encryption-aware.
 *
 * The algorithm is upstream's UPGRADE.md backfill, with three deliberate
 * differences:
 *
 * - Every legacy column is read through ConversationContent (ciphertext and
 *   plaintext alike), and `steps` / `meta` are written back sealed exactly
 *   the way the bound store writes them — encrypted when it is the
 *   EncryptedConversationStore, plaintext otherwise.
 * - A call still listed in `approval_state.pending` with no result anywhere
 *   is KEPT, carrying its `approval_reason`, and its row becomes `paused` —
 *   upstream drops such calls, which strands the pause. A paused row keeps
 *   its text and its calls in ONE step: 1.0 resumes from the calls of the
 *   latest assistant message, so the calls must not sit before a
 *   content-only step.
 * - It is idempotent and never touches the legacy columns: only rows whose
 *   `steps` is still NULL are written, so re-running it (the
 *   `ai-kit:backfill-conversation-steps` command) converts just the rows an
 *   old worker wrote mid-deploy, and old workers keep reading their columns.
 *
 * As upstream: one entry per round trip, each result folded onto the call
 * that produced it (a result recorded on a later row, as a 0.10 resume did,
 * still finds its call); `meta.reasoning` moves onto the step; raw provider
 * blocks (`meta.provider_content_blocks` / `provider_steps`) are dropped, and
 * so are the provider-specific `reasoning_*` call keys 1.0 no longer stores.
 */
final class StepsBackfill
{
    /**
     * @param  bool  $encrypt  seal `steps` / `meta` the way the EncryptedConversationStore does
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected bool $encrypt,
        protected int $chunk = 100,
    ) {}

    /**
     * A backfill over the configured conversation connection and table,
     * sealing the way the currently bound store does.
     */
    public static function configured(?string $connection = null, int $chunk = 100): self
    {
        return new self(
            DB::connection($connection ?? config('ai.conversations.connection')),
            (string) config('ai.conversations.tables.messages', 'agent_conversation_messages'),
            ConversationContent::encryptsAtRest(),
            $chunk,
        );
    }

    /**
     * Convert every row still missing `steps`, returning how many were written.
     */
    public function run(): int
    {
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($this->table) || ! $schema->hasColumn($this->table, 'steps')) {
            return 0;
        }

        $written = $this->connection->table($this->table)
            ->where('role', '!=', 'assistant')
            ->whereNull('steps')
            ->update(['steps' => '[]']);

        $after = null;

        while (true) {
            $conversationIds = $this->connection->table($this->table)
                ->where('role', 'assistant')
                ->whereNull('steps')
                ->when($after !== null, fn ($query) => $query->where('conversation_id', '>', $after))
                ->distinct()
                ->orderBy('conversation_id')
                ->limit($this->chunk)
                ->pluck('conversation_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            if ($conversationIds === []) {
                return $written;
            }

            foreach ($conversationIds as $conversationId) {
                $written += $this->connection->transaction(fn (): int => $this->backfill($conversationId));
            }

            $after = end($conversationIds);
        }
    }

    /**
     * Rewrite one conversation's unconverted assistant rows as steps.
     */
    protected function backfill(string $conversationId): int
    {
        $rows = $this->connection->table($this->table)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // A result was recorded on the row of the request that produced it, which may be a later row than its call...
        $results = $rows
            ->flatMap(fn (object $row): array => $this->column($row, 'tool_results'))
            ->filter(fn (mixed $result): bool => is_array($result) && isset($result['id']))
            ->keyBy('id');

        $written = 0;

        foreach ($rows as $row) {
            if ($row->steps !== null) {
                continue;
            }

            $meta = $this->column($row, 'meta');
            $pending = $this->column($row, 'approval_state')['pending'] ?? [];
            $pending = is_array($pending) ? $pending : [];

            $calls = $this->callsFor($row, $results, $pending);
            $paused = collect($calls)->contains(fn (array $call): bool => PendingApproval::isPending($call));

            $content = (string) ConversationContent::reveal((string) $row->content);
            $reasoning = is_string($meta['reasoning'] ?? null) ? $meta['reasoning'] : '';

            $steps = $calls !== [] && $content !== '' && ! $paused
                ? [StoredSteps::step('', $calls), StoredSteps::step($content, [], $reasoning)]
                : [StoredSteps::step($content, $calls, $reasoning)];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            $this->connection->table($this->table)->where('id', $row->id)->update([
                'steps' => $this->seal(json_encode($steps)),
                'meta' => $this->seal(json_encode($meta)),
                'status' => $paused ? MessageStatus::Paused->value : MessageStatus::Completed->value,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * The row's calls as 1.0 stores them: answered calls carry their result,
     * still-pending ones their approval reason; a legacy call with neither is
     * dangling and dropped, as upstream does.
     *
     * @param  Collection<string, array<string, mixed>>  $results
     * @param  array<string, mixed>  $pending
     * @return list<array<string, mixed>>
     */
    protected function callsFor(object $row, Collection $results, array $pending): array
    {
        return collect($this->column($row, 'tool_calls'))
            ->filter(fn (mixed $call): bool => is_array($call) && isset($call['id'], $call['name']))
            ->map(function (array $call) use ($results, $pending): ?array {
                $stored = Arr::except($call, ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);
                $stored['arguments'] = is_array($stored['arguments'] ?? null) ? $stored['arguments'] : [];

                if (array_key_exists('thought_signature', $stored) && $stored['thought_signature'] === null) {
                    unset($stored['thought_signature']);
                }

                if (($result = $results->get($call['id'])) !== null) {
                    return [
                        ...$stored,
                        'result' => $result['result'] ?? null,
                        ...array_filter([
                            'denied' => $result['denied'] ?? false,
                            'failed' => $result['failed'] ?? false,
                        ]),
                    ];
                }

                if (array_key_exists($call['id'], $pending)) {
                    $reason = $pending[$call['id']];

                    return [...$stored, 'approval_reason' => is_string($reason) && $reason !== '' ? $reason : null];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Read and decode a (possibly encrypted, possibly absent) JSON column.
     *
     * @return array<array-key, mixed>
     */
    protected function column(object $row, string $column): array
    {
        return property_exists($row, $column) && is_string($row->{$column})
            ? ConversationContent::revealJson($row->{$column})
            : [];
    }

    protected function seal(string $json): string
    {
        return $this->encrypt ? (string) ConversationContent::concealJson($json) : $json;
    }
}

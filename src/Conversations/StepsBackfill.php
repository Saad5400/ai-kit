<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;

/**
 * Rewrites laravel/ai 0.10 message rows (`tool_calls` / `tool_results` /
 * `approval_state`) onto 1.0's `steps` / `status`, encryption-aware.
 *
 * The algorithm is upstream's UPGRADE.md backfill — one entry per round trip,
 * each result folded onto the call that produced it (a result recorded on a
 * later row, as a 0.10 resume did, still finds its call), `meta.reasoning`
 * moved onto the step, raw provider blocks and the `reasoning_*` call keys
 * dropped — with these differences:
 *
 * - Legacy columns are read with ConversationContent::revealStrict(): a row
 *   holding ciphertext this app key cannot decrypt is LEFT ALONE (`steps`
 *   stays NULL, nothing else is written) and reported, never overwritten
 *   with its ciphertext passed off as text. A conversation with an
 *   undecryptable `tool_results` anywhere is left alone as a whole, since
 *   any of its calls might have been answered there.
 * - `steps` / `meta` are sealed the way the bound store writes them —
 *   encrypted when it is the EncryptedConversationStore — AND whenever the
 *   source row was ciphertext: an app that stopped encrypting never has
 *   data that was encrypted at rest decrypted by a migration.
 * - A call still listed in `approval_state.pending` with no result is KEPT,
 *   carrying its `approval_reason`, and its row becomes `paused` (upstream
 *   drops such calls, which strands the pause). A paused row keeps its text
 *   and its calls in ONE step: 1.0 resumes from the calls of the latest
 *   assistant message.
 * - Idempotent: only rows whose `steps` is still NULL are written (the UPDATE
 *   re-checks it, so a concurrent writer wins). `tool_calls` / `tool_results`
 *   / `approval_state` are never written; `meta` is rewritten without the
 *   moved keys.
 * - reconcile: a paused row converted earlier whose pending call an old
 *   (0.10) worker has since answered — the result lands in the legacy
 *   `tool_results` — gets that result folded onto the call, and completes
 *   once nothing is pending.
 *
 * Memory is bounded per conversation: a first pass reads only `id` +
 * `tool_results` to learn which row answered which call (ids only), then
 * rows convert one at a time, fetching another row's results only when a
 * call was answered there.
 */
final class StepsBackfill
{
    /** @var array{0: string|null, 1: array<string, array<string, mixed>>} */
    private array $resultsCache = [null, []];

    /** @var array<string, bool> */
    private array $columns;

    /**
     * @param  bool  $encrypt  seal `steps` / `meta` the way the EncryptedConversationStore does
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected bool $encrypt,
        protected int $chunk = 100,
    ) {
        $schema = $this->connection->getSchemaBuilder();

        $this->columns = collect(['steps', 'status', 'tool_calls', 'tool_results', 'approval_state'])
            ->mapWithKeys(fn (string $column): array => [$column => $schema->hasTable($this->table) && $schema->hasColumn($this->table, $column)])
            ->all();
    }

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
     * Whether the table still carries the 0.10 columns a row can need
     * converting from.
     */
    public function hasLegacyColumns(): bool
    {
        return $this->columns['steps'] && $this->columns['status']
            && $this->columns['tool_calls'] && $this->columns['tool_results'] && $this->columns['approval_state'];
    }

    /**
     * Convert every row still missing `steps` and reconcile every converted
     * pause an old worker has answered since.
     */
    public function run(): StepsBackfillReport
    {
        $report = new StepsBackfillReport;

        if (! $this->columns['steps']) {
            return $report;
        }

        $report->written += $this->query()
            ->where('role', '!=', 'assistant')
            ->whereNull('steps')
            ->update(['steps' => '[]']);

        $this->eachConversation(
            fn (Builder $query) => $query->where('role', 'assistant')->whereNull('steps'),
            fn (string $conversationId) => $this->backfill($conversationId, $report),
        );

        if ($this->hasLegacyColumns()) {
            $this->eachConversation(
                fn (Builder $query) => $this->legacyPauses($query),
                fn (string $conversationId) => $this->reconcile($conversationId, $report),
            );
        }

        return $report;
    }

    /**
     * Whether one conversation has rows an old worker wrote (or answered)
     * that are not on steps yet — the store's cheap self-heal probe.
     */
    public function needsHealing(string $conversationId): bool
    {
        return $this->hasLegacyColumns() && $this->query()
            ->where('conversation_id', $conversationId)
            ->where(fn (Builder $query) => $query->whereNull('steps')->orWhere(fn (Builder $query) => $this->legacyPauses($query)))
            ->exists();
    }

    /**
     * Convert and reconcile one conversation (the store's self-heal).
     */
    public function heal(string $conversationId): StepsBackfillReport
    {
        $report = new StepsBackfillReport;

        $report->written += $this->query()
            ->where('conversation_id', $conversationId)
            ->where('role', '!=', 'assistant')
            ->whereNull('steps')
            ->update(['steps' => '[]']);

        $this->backfill($conversationId, $report);
        $this->reconcile($conversationId, $report);

        return $report;
    }

    /**
     * Rewrite one conversation's unconverted assistant rows as steps.
     */
    protected function backfill(string $conversationId, StepsBackfillReport $report): void
    {
        $this->resultsCache = [null, []];
        $pending = $this->assistantRows($conversationId)->whereNull('steps');

        if (! $pending->clone()->exists()) {
            return;
        }

        if (($sources = $this->resultSources($conversationId)) === null) {
            $pending->clone()->pluck('id')->each(fn ($id) => $report->skip((string) $id));

            return;
        }

        $columns = array_values(array_filter(
            ['id', 'content', 'meta', 'tool_calls', 'tool_results', 'approval_state'],
            fn (string $column): bool => in_array($column, ['id', 'content', 'meta'], true) || $this->columns[$column],
        ));

        foreach ($pending->select($columns)->lazyById($this->chunk, 'id') as $row) {
            try {
                $content = (string) ConversationContent::revealStrict((string) $row->content);
                $meta = $this->json($row, 'meta');
                $state = $this->json($row, 'approval_state');
                $pendingReasons = is_array($state['pending'] ?? null) ? $state['pending'] : [];
                $calls = $this->callsFor($this->json($row, 'tool_calls'), $row, $sources, $pendingReasons);
            } catch (UndecryptableConversationContent) {
                $report->skip((string) $row->id);

                continue;
            }

            $paused = collect($calls)->contains(fn (array $call): bool => PendingApproval::isPending($call));
            $reasoning = is_string($meta['reasoning'] ?? null) ? $meta['reasoning'] : '';

            $steps = $calls !== [] && $content !== '' && ! $paused
                ? [StoredSteps::step('', $calls), StoredSteps::step($content, [], $reasoning)]
                : [StoredSteps::step($content, $calls, $reasoning)];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            $seal = $this->encrypt || $this->wasSealed($row);

            $report->written += $this->query()
                ->where('id', $row->id)
                ->whereNull('steps')
                ->update([
                    'steps' => $this->seal($this->encode($steps), $seal),
                    'meta' => $this->seal($this->encode($meta), $seal),
                    'status' => $paused ? MessageStatus::Paused->value : MessageStatus::Completed->value,
                ]);
        }
    }

    /**
     * Fold results an old worker recorded after the conversion onto the
     * pending calls of the conversation's converted pauses.
     */
    protected function reconcile(string $conversationId, StepsBackfillReport $report): void
    {
        $this->resultsCache = [null, []];
        $paused = $this->legacyPauses($this->assistantRows($conversationId))->whereNotNull('steps');

        if (! $paused->clone()->exists()) {
            return;
        }

        if (($sources = $this->resultSources($conversationId)) === null) {
            $paused->clone()->pluck('id')->each(fn ($id) => $report->skip((string) $id));

            return;
        }

        foreach ($paused->select(['id', 'steps'])->lazyById($this->chunk, 'id') as $row) {
            try {
                [$steps, $changed, $stillPending] = $this->answered(ConversationContent::revealJsonStrict((string) $row->steps), $sources);
            } catch (UndecryptableConversationContent) {
                $report->skip((string) $row->id);

                continue;
            }

            if (! $changed) {
                continue;
            }

            $report->reconciled += $this->query()
                ->where('id', $row->id)
                ->where('status', MessageStatus::Paused->value)
                ->update([
                    'steps' => $this->seal($this->encode($steps), $this->encrypt || ConversationContent::looksEncrypted((string) $row->steps)),
                    'status' => $stillPending ? MessageStatus::Paused->value : MessageStatus::Completed->value,
                ]);
        }
    }

    /**
     * Fold the recorded results onto a converted pause's pending calls.
     *
     * @param  array<array-key, mixed>  $steps
     * @param  array<string, string>  $sources
     * @return array{0: array<array-key, mixed>, 1: bool, 2: bool} the steps, whether any call was answered, whether any is still pending
     */
    protected function answered(array $steps, array $sources): array
    {
        $changed = false;
        $stillPending = false;

        foreach ($steps as $s => $step) {
            foreach (is_array($step['tool_calls'] ?? null) ? $step['tool_calls'] : [] as $c => $call) {
                if (! is_array($call) || ! PendingApproval::isPending($call)) {
                    continue;
                }

                $result = isset($sources[$call['id'] ?? '']) ? $this->resultFrom($sources[$call['id']], $call['id']) : null;

                if ($result === null) {
                    $stillPending = true;

                    continue;
                }

                $steps[$s]['tool_calls'][$c] = [...$call, ...$this->answer($result)];
                $changed = true;
            }
        }

        return [$steps, $changed, $stillPending];
    }

    /**
     * Which row answered which call, by id only — or null when some row's
     * results cannot be decrypted, so no call can be trusted to
     * be unanswered.
     *
     * @return array<string, string>|null
     */
    protected function resultSources(string $conversationId): ?array
    {
        if (! $this->columns['tool_results']) {
            return [];
        }

        $sources = [];
        $readable = true;

        $rows = $this->assistantRows($conversationId)
            ->whereNotNull('tool_results')
            ->where('tool_results', '!=', '[]')
            ->select(['id', 'tool_results']);

        foreach ($rows->lazyById($this->chunk, 'id') as $row) {
            try {
                $results = ConversationContent::revealJsonStrict((string) $row->tool_results);
            } catch (UndecryptableConversationContent) {
                $readable = false;

                continue;
            }

            foreach ($results as $result) {
                if (is_array($result) && isset($result['id']) && (is_string($result['id']) || is_int($result['id']))) {
                    $sources[(string) $result['id']] = (string) $row->id;
                }
            }

            unset($results);
        }

        return $readable ? $sources : null;
    }

    /**
     * The row's calls as 1.0 stores them: answered calls carry their result,
     * still-pending ones their approval reason; a legacy call with neither is
     * dangling and dropped, as upstream does.
     *
     * @param  array<array-key, mixed>  $calls
     * @param  array<string, string>  $sources
     * @param  array<array-key, mixed>  $pending
     * @return list<array<string, mixed>>
     */
    protected function callsFor(array $calls, object $row, array $sources, array $pending): array
    {
        $stored = [];

        foreach ($calls as $call) {
            if (! is_array($call) || ! isset($call['id'], $call['name'])) {
                continue;
            }

            $id = (string) $call['id'];
            $entry = Arr::except($call, ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);
            $entry['arguments'] = is_array($entry['arguments'] ?? null) ? $entry['arguments'] : [];

            if (array_key_exists('thought_signature', $entry) && $entry['thought_signature'] === null) {
                unset($entry['thought_signature']);
            }

            $result = isset($sources[$id]) ? $this->resultFrom($sources[$id], $id, $row) : null;

            if ($result !== null) {
                $stored[] = [...$entry, ...$this->answer($result)];
            } elseif (array_key_exists($id, $pending)) {
                $reason = $pending[$id];
                $stored[] = [...$entry, 'approval_reason' => is_string($reason) && $reason !== '' ? $reason : null];
            }
        }

        return $stored;
    }

    /**
     * The stored result for a call, read from the row that recorded it — the
     * row in hand when it is the same one, else one fetch (cached for the
     * run of calls a row usually answers together).
     *
     * @return array<string, mixed>|null
     */
    protected function resultFrom(string $sourceId, string $callId, ?object $current = null): ?array
    {
        if ($this->resultsCache[0] !== $sourceId) {
            $raw = $current !== null && (string) $current->id === $sourceId && property_exists($current, 'tool_results')
                ? $current->tool_results
                : $this->query()->where('id', $sourceId)->value('tool_results');

            $this->resultsCache = [$sourceId, collect(ConversationContent::revealJsonStrict(is_string($raw) ? $raw : null))
                ->filter(fn (mixed $result): bool => is_array($result) && isset($result['id']))
                ->keyBy(fn (array $result): string => (string) $result['id'])
                ->all()];
        }

        return $this->resultsCache[1][$callId] ?? null;
    }

    /**
     * The answer keys a stored call carries.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function answer(array $result): array
    {
        return [
            'result' => $result['result'] ?? null,
            ...array_filter([
                'denied' => $result['denied'] ?? false,
                'failed' => $result['failed'] ?? false,
            ]),
        ];
    }

    /**
     * Paused rows still carrying a 0.10 pause marker — converted from 0.10,
     * so an old worker may have answered them in the legacy columns.
     */
    protected function legacyPauses(Builder $query): Builder
    {
        return $query->where('role', 'assistant')
            ->where('status', MessageStatus::Paused->value)
            ->whereNotNull('approval_state');
    }

    /**
     * Run $each over the distinct conversations the filter matches, keyset-
     * paginated so rows converted along the way never shift a page.
     *
     * @param  callable(Builder): Builder  $filter
     * @param  callable(string): void  $each
     */
    protected function eachConversation(callable $filter, callable $each): void
    {
        $after = null;

        while (true) {
            $conversationIds = $filter($this->query())
                ->when($after !== null, fn ($query) => $query->where('conversation_id', '>', $after))
                ->distinct()
                ->orderBy('conversation_id')
                ->limit($this->chunk)
                ->pluck('conversation_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            if ($conversationIds === []) {
                return;
            }

            foreach ($conversationIds as $conversationId) {
                $this->connection->transaction(fn () => $each($conversationId));
            }

            $after = end($conversationIds);
        }
    }

    /**
     * Read and decode a (possibly encrypted, possibly absent) JSON column.
     *
     * @return array<array-key, mixed>
     *
     * @throws UndecryptableConversationContent
     */
    protected function json(object $row, string $column): array
    {
        return property_exists($row, $column) && is_string($row->{$column})
            ? ConversationContent::revealJsonStrict($row->{$column})
            : [];
    }

    /**
     * Whether any column of the source row was stored encrypted.
     */
    protected function wasSealed(object $row): bool
    {
        foreach (['content', 'meta', 'tool_calls', 'tool_results', 'approval_state'] as $column) {
            if (property_exists($row, $column) && ConversationContent::looksEncrypted(is_string($row->{$column}) ? $row->{$column} : null)) {
                return true;
            }
        }

        return false;
    }

    protected function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    protected function seal(string $json, bool $seal): string
    {
        return $seal ? (string) ConversationContent::concealJson($json) : $json;
    }

    protected function assistantRows(string $conversationId): Builder
    {
        return $this->query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant');
    }

    protected function query(): Builder
    {
        return $this->connection->table($this->table);
    }
}

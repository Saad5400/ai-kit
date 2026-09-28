<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Storage\StoredMessage;
use Throwable;

/**
 * Conversation store that keeps chat history private at rest (laravel/ai 1.0
 * `steps` / `status` schema).
 *
 * - Message content is encrypted with the app key (Crypt) before it touches
 *   the database, and decrypted on read. Decryption tolerates plaintext so
 *   rows written before encryption was enabled keep reading back.
 * - Tool traces are governed by `ai-kit.conversations.persist_tool_traces`
 *   (owner decision #7). When ON, attachments / steps (tool calls with their
 *   results, reasoning, replay blocks) / meta persist ENCRYPTED (usage stays
 *   plaintext — aggregate numbers, no user content), which is what makes
 *   laravel/ai's Approvable pause/resume usable without plaintext traces at
 *   rest; `ai-kit:prune-conversations` strips traces past the separate
 *   `trace_retention_days` window. When OFF, an assistant row keeps a
 *   content-only `steps` (1.0 replays assistant text from `steps`, not the
 *   `content` column), attachments / usage are `'[]'` and meta keeps only a
 *   failed turn's `error`; a resume then raises ApprovalMismatchException
 *   instead of silently persisting the withheld traces.
 *
 * Empty markers (`'[]'`, `null`) are stored as-is — never encrypted — so SQL
 * emptiness predicates keep meaning what they say. `status` is never
 * encrypted: the vendor queries it.
 *
 * Reads go through the parent's single JSON seam, decoded(), plus
 * userMessageFrom() for the user row's text and attachments. Writes: inserts
 * funnel through messageAttributes(); the three vendor methods that UPDATE
 * rows (resumePausedRow, forgetReplayBlocks, storeApprovalResults) and the
 * one that hands raw rows out (paginateConversationMessages) are reproduced
 * here with sealing folded in. The drift guard pins each of those vendor
 * bodies, so an upstream change fails loudly instead of skewing silently.
 */
class EncryptedConversationStore extends DatabaseConversationStore
{
    /**
     * Create a new encrypted conversation store instance.
     *
     * @param  bool|null  $persistToolTraces  null defers to `ai-kit.conversations.persist_tool_traces`.
     */
    public function __construct(?string $connection = null, protected ?bool $persistToolTraces = null)
    {
        parent::__construct($connection);
    }

    /**
     * Build the message row attributes, sealed under the trace policy.
     *
     * Both storeUserMessage and storeAssistantMessage funnel through this
     * parent seam, so one override covers every insert.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        return $this->sealed(
            parent::messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, $attributes),
            (string) ($attributes['role'] ?? 'assistant'),
        );
    }

    /**
     * Decode a stored JSON column, decrypting it first.
     *
     * The parent's single read seam: `steps`, `meta` and `usage` all come
     * through here, on every path (history, pendingApprovalsFor, pause
     * lookup, resume merge).
     *
     * @return array<array-key, mixed>
     */
    protected function decoded(?string $json): array
    {
        return parent::decoded(ConversationContent::reveal($json));
    }

    /**
     * Rebuild a stored user turn from its decrypted text and attachments.
     */
    protected function userMessageFrom(object $record): Message
    {
        return parent::userMessageFrom($this->revealed($record));
    }

    /**
     * Append the steps a resumed run made to the row its turn paused on —
     * vendor logic, sealing what goes back.
     */
    protected function resumePausedRow(string $conversationId, object $paused, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): string
    {
        $steps = $this->decodedSteps($paused);

        if (($this->decoded($paused->meta)['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        if ($response->steps->isNotEmpty()) {
            $steps = $steps->concat($this->stepsFor($prompt, $response));
        }

        if (! $response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        $now = now();

        $this->table($this->messagesTable())->where('id', $paused->id)->update($this->sealed([
            'content' => blank($response->text) ? (string) ConversationContent::reveal($paused->content) : $response->text,
            'steps' => $steps->toJson(),
            'usage' => json_encode(TextUsage::fromArray($this->decoded($paused->usage))->add($response->usage)),
            'meta' => json_encode($this->mergedMeta($paused, $response, $exception)),
            'status' => $this->statusFor($response, $exception),
            'updated_at' => $now,
        ], 'assistant'));

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->touchConversation($conversationId, $now);

        return $paused->id;
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn
     * resumed from — vendor logic, sealing the rewritten steps.
     */
    protected function forgetReplayBlocks(string $conversationId): void
    {
        $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('status', MessageStatus::Paused)
            ->get(['id', 'steps'])
            ->each(function (object $record): void {
                $steps = $this->decodedSteps($record);

                if ($steps->every(fn (array $step): bool => $step['replay_blocks'] === [])) {
                    return;
                }

                $this->table($this->messagesTable())->where('id', $record->id)->update($this->sealed([
                    'steps' => $this->withoutReplayBlocks($steps)->toJson(),
                ], 'assistant'));
            });
    }

    /**
     * Durably record resolved approval results on the paused turn before the
     * run continues — vendor logic, sealing the rewritten steps.
     *
     * Like the vendor store this looks the paused turn up by conversation id
     * ONLY: authorize the resuming participant before passing decisions to
     * the agent (see the README, "Resuming a paused turn").
     *
     * @param  array<int, ToolResult>  $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        DB::connection($this->connection)->transaction(function () use ($conversationId, $toolResults, $resultIds) {
            $paused = $this->assistantRows($conversationId)
                ->where('status', MessageStatus::Paused)
                ->lockForUpdate()
                ->get();

            $row = $paused->first(fn ($record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $paused->first() === null ? collect() : $this->pendingApprovalsIn($paused->first()),
                );
            }

            $resolved = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

            $steps = $this->decodedSteps($row)->map(function (array $step) use ($resolved): array {
                $step['tool_calls'] = array_map(function (array $toolCall) use ($resolved): array {
                    $result = $resolved->get($toolCall['id'] ?? '');

                    return $result === null || PendingApproval::isAnswered($toolCall)
                        ? $toolCall
                        // Arguments come along because an edited approval runs the tool with different ones than the call asked for...
                        : [...$toolCall, ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed'])];
                }, $step['tool_calls']);

                return $step;
            });

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update($this->sealed(['steps' => $steps->toJson(), 'updated_at' => now()], 'assistant'));
        });
    }

    /**
     * Paginate the given conversation's messages, newest first, decrypted —
     * vendor logic with each row revealed before it is decoded.
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $cursor)
            ->through(fn (object $record): StoredMessage => StoredMessage::fromArray((array) $this->revealed($record)));
    }

    /**
     * Seal a row's columns for storage under the trace policy: content
     * encrypted, JSON columns encrypted (empty markers left plaintext), and —
     * with traces off — an assistant row reduced to content-only steps.
     *
     * Only the keys present are touched, so it serves inserts and partial
     * updates alike.
     *
     * @param  array<string, mixed>  $attributes  plaintext column values
     * @return array<string, mixed>
     */
    protected function sealed(array $attributes, string $role): array
    {
        if (! $this->shouldPersistToolTraces()) {
            $attributes = $this->withoutTraces($attributes, $role);
        }

        if (array_key_exists('content', $attributes)) {
            $attributes['content'] = ConversationContent::conceal($attributes['content']);
        }

        foreach (['attachments', 'steps', 'meta'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = ConversationContent::concealJson($attributes[$column]);
            }
        }

        return $attributes;
    }

    /**
     * Apply the traces-off policy to plaintext row attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withoutTraces(array $attributes, string $role): array
    {
        foreach (['attachments', 'usage'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = '[]';
            }
        }

        if (array_key_exists('steps', $attributes)) {
            $attributes['steps'] = $role === 'assistant'
                ? json_encode(StoredSteps::contentOnly(
                    parent::decoded($attributes['steps']),
                    isset($attributes['content']) ? (string) $attributes['content'] : null,
                ))
                : '[]';
        }

        if (array_key_exists('meta', $attributes)) {
            $error = parent::decoded($attributes['meta'])['error'] ?? null;

            // A failed turn's error is the only meta kept: it is what tells
            // a reader why the turn stopped, not a trace of what it did.
            $attributes['meta'] = $error === null ? '[]' : json_encode(['error' => $error]);
        }

        return $attributes;
    }

    /**
     * Decrypt a fetched message record's protected columns (on a clone),
     * tolerating legacy plaintext values throughout.
     */
    protected function revealed(object $record): object
    {
        $record = clone $record;

        foreach (['content', 'attachments', 'steps', 'meta'] as $column) {
            if (property_exists($record, $column) && is_string($record->{$column})) {
                $record->{$column} = ConversationContent::reveal($record->{$column});
            }
        }

        return $record;
    }

    /**
     * Determine whether tool traces should be persisted on message rows.
     */
    protected function shouldPersistToolTraces(): bool
    {
        return $this->persistToolTraces
            ?? (bool) config('ai-kit.conversations.persist_tool_traces', true);
    }
}

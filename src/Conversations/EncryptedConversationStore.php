<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Conversation store that keeps chat history private at rest.
 *
 * - Message content is encrypted with the app key (Crypt) before it touches
 *   the database, and decrypted on read. Decryption tolerates plaintext so
 *   rows written before encryption was enabled keep reading back.
 * - Tool traces are governed by `ai-kit.conversations.persist_tool_traces`
 *   (owner decision #7): when ON, attachments / tool calls / tool results /
 *   meta / the approval pause marker persist ENCRYPTED (usage stays
 *   plaintext — aggregate numbers, no user content), which is what makes
 *   laravel/ai's Approvable pause/resume usable without plaintext traces at
 *   rest; `ai-kit:prune-conversations` strips traces past the separate
 *   `trace_retention_days` window. When OFF they are stored as `'[]'`, and
 *   a resume raises ApprovalMismatchException instead of silently
 *   persisting the withheld traces.
 *
 * Empty markers (`'[]'`, `null`) are stored as-is — never encrypted — so
 * the vendor's SQL emptiness predicates (`tool_results != '[]'`,
 * `approval_state IS NOT NULL`) keep meaning what they say.
 *
 * The vendor read paths json_decode raw columns, so the three methods that
 * touch trace columns are reproduced here with decryption folded in; the
 * drift-guard test pins the vendor source so an upstream change to any of
 * them fails loudly instead of skewing silently.
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
     * Build the message row attributes, encrypting content and applying the trace policy.
     *
     * Both storeUserMessage and storeAssistantMessage funnel through this
     * parent seam, so one override covers every insert.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        $attributes = parent::messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, $attributes);

        $attributes['content'] = $this->encrypt($attributes['content']);

        if (! $this->shouldPersistToolTraces()) {
            return array_merge($attributes, [
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
            ]);
        }

        foreach (['attachments', 'tool_calls', 'tool_results', 'meta', 'approval_state'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = $this->encryptJson($attributes[$column]);
            }
        }

        return $attributes;
    }

    /**
     * Get the latest messages for the given conversation, decrypted.
     *
     * Vendor logic with one change: every record is decrypted BEFORE the
     * reconstruction reads it, so tool turns, pauses and attachments
     * rehydrate from plaintext JSON. The inherited helpers
     * (reconstructToolTurn, rehydrateAttachments, pausedCallIds) then run
     * unchanged on the decrypted records.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $records = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (object $record): object => $this->decryptRecord($record));

        $resolvedCallIds = $records
            ->flatMap(fn ($record) => collect(json_decode((string) $record->tool_results, true))->pluck('id'))
            ->filter()
            ->all();

        return $records
            ->flatMap(function ($record) use ($resolvedCallIds): array {
                $toolCalls = collect(json_decode((string) $record->tool_calls, true))->values();
                $toolResults = collect(json_decode((string) $record->tool_results, true))->values();

                if ($record->role === 'user') {
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    return $this->reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
                }

                if ($toolResults->isNotEmpty()) {
                    $messages = [new ToolResultMessage($toolResults->map(ToolResult::fromArray(...)))];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            })
            ->skipWhile(fn (Message $message) => $message instanceof ToolResultMessage)
            ->values();
    }

    /**
     * Get every tool-result ID recorded on the conversation's approval-paused rows, decrypting each row's results first.
     *
     * @return array<int, string>
     */
    protected function existingToolResultIds(string $conversationId): array
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('approval_state')
            ->where('tool_results', '!=', '[]')
            ->pluck('tool_results')
            ->flatMap(fn ($results) => collect(json_decode((string) $this->decrypt($results), true))->pluck('id'))
            ->filter()
            ->all();
    }

    /**
     * Get the tool-call IDs a stored row recorded as pending a decision,
     * tolerating both encrypted and legacy plaintext markers.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        $record = clone $record;
        $record->approval_state = $this->decrypt($record->approval_state ?? null);

        return parent::pausedCallIds($record);
    }

    /**
     * Merge a resume's resolved approval results into the paused row —
     * vendor logic, decrypting the row before the merge and re-encrypting
     * what goes back.
     *
     * @param  array<int, ToolResult>  $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        DB::connection($this->connection)->transaction(function () use ($conversationId, $participantType, $participantId, $toolResults, $resultIds) {
            $row = $this->table($this->messagesTable())
                ->where('conversation_id', $conversationId)
                ->when($participantId === null,
                    fn ($query) => $query->whereNull('participant_type')->whereNull('participant_id'),
                    fn ($query) => $query->where('participant_type', $participantType)->where('participant_id', $participantId))
                ->where('role', 'assistant')
                ->whereNotNull('approval_state')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->first(fn ($record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException('The approval results do not match a paused conversation turn.', collect());
            }

            $existing = collect(json_decode((string) $this->decrypt($row->tool_results), true) ?: []);

            $merged = $existing->merge(
                collect($toolResults)->reject(fn (ToolResult $result) => $existing->contains('id', $result->id))
            );

            $pending = collect(((array) json_decode((string) $this->decrypt($row->approval_state ?? null) ?: 'null', true))['pending'] ?? [])->except($resultIds);

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update([
                    'tool_results' => $this->encryptJson($merged->values()->toJson()),
                    'approval_state' => $this->encryptJson(json_encode(['pending' => $pending->all()])),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Decrypt a fetched message record's protected columns in place (on a
     * clone), tolerating legacy plaintext values throughout.
     */
    protected function decryptRecord(object $record): object
    {
        $record = clone $record;

        foreach (['content', 'attachments', 'tool_calls', 'tool_results', 'meta', 'approval_state'] as $column) {
            if (property_exists($record, $column)) {
                $record->{$column} = $this->decrypt($record->{$column});
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

    /**
     * Encrypt content for storage, leaving blank content blank.
     *
     * An empty string encrypts to a non-empty ciphertext, which would flip
     * the parent's filled()/blank() checks on stored rows.
     */
    protected function encrypt(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return $content;
        }

        return Crypt::encryptString($content);
    }

    /**
     * Encrypt a serialized JSON column, leaving empty markers plaintext so
     * SQL emptiness predicates keep working.
     */
    protected function encryptJson(?string $value): ?string
    {
        if ($value === null || in_array($value, ['', '[]', '{}', 'null'], true)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Decrypt a stored value, tolerating pre-encryption plaintext rows.
     * Apps reading message rows directly use the same logic through
     * {@see ConversationContent::reveal()}.
     */
    protected function decrypt(?string $value): ?string
    {
        return ConversationContent::reveal($value);
    }
}

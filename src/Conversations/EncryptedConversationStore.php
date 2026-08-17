<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Conversation store that keeps chat history private at rest.
 *
 * - Message content is encrypted with the app key (Crypt) before it touches
 *   the database, and decrypted on read. Decryption tolerates plaintext so
 *   rows written before encryption was enabled keep reading back.
 * - By default nothing else is persisted: attachments (file bytes /
 *   references), tool call/result traces, usage and meta are stored as
 *   `'[]'` — traces can carry user data. Set
 *   `ai-kit.conversations.persist_tool_traces` to true to keep them
 *   (plaintext, as the vendor store writes them; only content is encrypted).
 *
 * Trade-off to know about: laravel/ai's built-in tool-approval pause/resume
 * (Approvable) reconstructs paused turns from the stored traces and the
 * `approval_state` marker. With trace persistence off those are blanked, so
 * a resume raises ApprovalMismatchException instead of silently persisting
 * the withheld traces — enable `persist_tool_traces` if you use Approvable.
 *
 * Everything else (approval bookkeeping, tool-turn reconstruction, table and
 * connection resolution) is inherited from the vendor store unchanged.
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
            $attributes = array_merge($attributes, [
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
            ]);
        }

        return $attributes;
    }

    /**
     * Get the latest messages for the given conversation, decrypted.
     *
     * The parent's reconstruction logic only inspects stored content through
     * filled()/blank() — which ciphertext preserves, because blank content is
     * stored blank — so the messages can be decrypted after the fact.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return parent::getLatestConversationMessages($conversationId, $limit)
            ->each(function (Message $message): void {
                $message->content = $this->decrypt($message->content);
            });
    }

    /**
     * Determine whether tool traces should be persisted on message rows.
     */
    protected function shouldPersistToolTraces(): bool
    {
        return $this->persistToolTraces
            ?? (bool) config('ai-kit.conversations.persist_tool_traces', false);
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
     * Decrypt a stored value, tolerating pre-encryption plaintext rows.
     * Apps reading message rows directly use the same logic through
     * {@see ConversationContent::reveal()}.
     */
    protected function decrypt(?string $value): ?string
    {
        return ConversationContent::reveal($value);
    }
}

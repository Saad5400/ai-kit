<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;

/**
 * Seeds conversation rows in the laravel/ai 0.10 shape (tool_calls /
 * tool_results / approval_state, no steps), sealed the way the kit's 0.10
 * EncryptedConversationStore wrote them — the input the steps migration has
 * to convert in production.
 */
final class LegacyConversationRows
{
    public const MESSAGES = 'agent_conversation_messages';

    public const OWNER_TYPE = 'stdClass';

    public const OWNER_ID = '7';

    /**
     * Put the messages table back in its 0.10 shape: dropped, then re-created
     * by the kit's original (0.10) conversation migration.
     */
    public static function rewindSchema(): void
    {
        Schema::drop(self::MESSAGES);

        (require __DIR__.'/../../database/migrations/2026_08_17_000000_create_agent_conversations_tables.php')->up();
    }

    /**
     * Run the kit's steps migration.
     */
    public static function migrate(): void
    {
        (require __DIR__.'/../../database/migrations/2026_09_28_000000_move_agent_conversation_messages_onto_steps.php')->up();
    }

    public static function conversation(string $title = 'Legacy chat'): string
    {
        $id = (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $id,
            'participant_type' => self::OWNER_TYPE,
            'participant_id' => self::OWNER_ID,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Insert one 0.10-shaped message row; JSON values are arrays, sealed on
     * the way in unless $encrypt is off. Empty markers stay plaintext, as the
     * 0.10 store left them.
     *
     * @param  array<string, mixed>  $columns
     */
    public static function row(string $conversationId, string $role, string $content, array $columns = [], bool $encrypt = true): string
    {
        $id = (string) Str::uuid7();

        // uuid7 ids sort by time; spacing inserts keeps them strictly ordered.
        usleep(1000);

        $json = function (mixed $value) use ($encrypt): ?string {
            if ($value === null) {
                return null;
            }

            $encoded = is_string($value) ? $value : json_encode($value);

            return $encrypt && ! in_array($encoded, ['[]', '{}'], true) ? Crypt::encryptString($encoded) : $encoded;
        };

        DB::table(self::MESSAGES)->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => self::OWNER_TYPE,
            'participant_id' => self::OWNER_ID,
            'agent' => RememberingApprovalAgent::class,
            'role' => $role,
            'content' => $encrypt && $content !== '' ? Crypt::encryptString($content) : $content,
            'attachments' => $json($columns['attachments'] ?? '[]'),
            'tool_calls' => $json($columns['tool_calls'] ?? '[]'),
            'tool_results' => $json($columns['tool_results'] ?? '[]'),
            'usage' => json_encode($columns['usage'] ?? ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            'meta' => $json($columns['meta'] ?? '[]'),
            'approval_state' => $json($columns['approval_state'] ?? null),
            'created_at' => $columns['created_at'] ?? now(),
            'updated_at' => $columns['created_at'] ?? now(),
        ]);

        return $id;
    }

    /**
     * A 0.10 tool call as ToolCall::toArray() serialized it.
     *
     * @return array<string, mixed>
     */
    public static function call(string $id, string $name, array $arguments = ['id' => 4]): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'arguments' => $arguments,
            'result_id' => null,
            'reasoning_id' => null,
            'reasoning_summary' => null,
            'reasoning_encrypted_content' => null,
        ];
    }

    /**
     * A 0.10 tool result as ToolResult::toArray() serialized it.
     *
     * @return array<string, mixed>
     */
    public static function result(string $id, string $name, string $result, array $arguments = ['id' => 4]): array
    {
        return ['id' => $id, 'name' => $name, 'arguments' => $arguments, 'result' => $result, 'result_id' => null];
    }

    /**
     * Flatten replayed messages into comparable arrays.
     *
     * @param  iterable<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function transcript(iterable $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = match (true) {
                $message instanceof ToolResultMessage => [
                    'tool' => $message->toolResults->map(fn ($result) => [$result->id, $result->result])->all(),
                ],
                $message instanceof AssistantMessage => [
                    'assistant' => $message->content,
                    'calls' => $message->toolCalls->map(fn ($call) => $call->id)->all(),
                ],
                default => ['user' => $message->content],
            };
        }

        return $out;
    }
}

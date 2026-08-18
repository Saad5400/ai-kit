<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Conversations\EncryptedConversationStore;

uses(RefreshDatabase::class);

/**
 * These tests drive the store directly rather than the bound contract —
 * StoreBindingTest and ConversationStoreEncryptOptOutTest own the binding.
 */
function encryptedStore(): EncryptedConversationStore
{
    return new EncryptedConversationStore;
}

function conversationsAgent(): Agent
{
    return new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test agent';
        }
    };
}

function conversationsPrompt(string $text = 'hello there', array $attachments = []): AgentPrompt
{
    return new AgentPrompt(
        conversationsAgent(),
        $text,
        $attachments,
        app(AiManager::class)->textProvider('openrouter'),
        'test/model',
    );
}

function conversationsResponse(string $text = 'assistant reply', bool $withTools = false): AgentResponse
{
    $response = new AgentResponse(
        (string) Str::uuid7(),
        $text,
        new Usage(promptTokens: 10, completionTokens: 5),
        new Meta('openrouter', 'test/model'),
    );

    if ($withTools) {
        $response->withToolCallsAndResults(
            collect([new ToolCall('call_1', 'lookup', ['q' => 'x'])]),
            collect([new ToolResult('call_1', 'lookup', ['q' => 'x'], 'tool output')]),
        );
    }

    return $response;
}

it('encrypts message content at rest and decrypts it on read', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeUserMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt('the user secret'));
    $messageId = $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt('the user secret'), conversationsResponse('the assistant secret'));

    expect($messageId)->toBeString();

    $rows = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->content)->not->toBe('the user secret')
        ->and($rows[1]->content)->not->toBe('the assistant secret')
        ->and(Crypt::decryptString($rows[0]->content))->toBe('the user secret')
        ->and(Crypt::decryptString($rows[1]->content))->toBe('the assistant secret');

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role->value)->toBe('user')
        ->and($messages[0]->content)->toBe('the user secret')
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->content)->toBe('the assistant secret');
});

it('stores empty JSON for attachments and tool traces by default', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $prompt = conversationsPrompt('with attachment', [['type' => 'file', 'name' => 'cv.pdf']]);
    $store->storeUserMessage($conversationId, 'App\\Models\\User', '7', $prompt);
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', $prompt, conversationsResponse(withTools: true));

    [$userRow, $assistantRow] = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($userRow->attachments)->toBe('[]')
        ->and($assistantRow->tool_calls)->toBe('[]')
        ->and($assistantRow->tool_results)->toBe('[]')
        ->and($assistantRow->usage)->toBe('[]')
        ->and($assistantRow->meta)->toBe('[]')
        ->and($assistantRow->approval_state)->toBeNull();
});

it('persists tool traces when configured, still encrypting content', function () {
    config()->set('ai-kit.conversations.persist_tool_traces', true);

    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse('traced reply', withTools: true));

    $row = DB::table('agent_conversation_messages')->sole();

    expect($row->tool_calls)->toContain('call_1')
        ->and($row->tool_results)->toContain('tool output')
        ->and(json_decode($row->usage, true))->toMatchArray(['prompt_tokens' => 10, 'completion_tokens' => 5])
        ->and($row->content)->not->toBe('traced reply')
        ->and(Crypt::decryptString($row->content))->toBe('traced reply');

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages->last()->content)->toBe('traced reply');
});

it('reads pre-encryption plaintext rows back as-is', function () {
    $store = encryptedStore();
    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'Legacy chat');

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'App\\Models\\User',
        'participant_id' => '7',
        'agent' => 'App\\LegacyAgent',
        'role' => 'assistant',
        'content' => 'stored before encryption',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->content)->toBe('stored before encryption');
});

it('keeps blank content blank so stored-row filled checks stay truthful', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse(''));

    expect(DB::table('agent_conversation_messages')->sole()->content)->toBe('');
});

it('supports string participant ids end to end', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation(null, 'telegram:123456789', 'Anonymous chat');
    $store->storeUserMessage($conversationId, null, 'telegram:123456789', conversationsPrompt('anonymous message'));

    expect($store->latestConversationId('', 'telegram:123456789'))->toBeNull();

    $row = DB::table('agent_conversations')->sole();

    expect($row->participant_id)->toBe('telegram:123456789')
        ->and($row->participant_type)->toBeNull()
        ->and($store->getLatestConversationMessages($conversationId, 5)->first()->content)->toBe('anonymous message');
});

it('honors an explicit persistToolTraces constructor override', function () {
    config()->set('ai-kit.conversations.persist_tool_traces', false);

    $store = new EncryptedConversationStore(persistToolTraces: true);

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse(withTools: true));

    expect(DB::table('agent_conversation_messages')->sole()->tool_calls)->toContain('call_1');
});

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\EncryptedConversationStore;

uses(RefreshDatabase::class);

/**
 * These tests drive the store directly rather than the bound contract —
 * StoreBindingTest and ConversationStoreEncryptOptOutTest own the binding,
 * EncryptedStoreTurnsTest runs whole agent turns through it.
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

function conversationsPrompt(string $text = 'hello there'): AgentPrompt
{
    return new AgentPrompt(
        conversationsAgent(),
        $text,
        [],
        app(AiManager::class)->textProvider('openrouter'),
        'test/model',
    );
}

function conversationsResponse(string $text = 'assistant reply', bool $withTools = false): AgentResponse
{
    $response = new AgentResponse(
        (string) Str::uuid7(),
        $text,
        new TextUsage(inputTokens: 10, outputTokens: 5),
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

function storeUserTurn(EncryptedConversationStore $store, string $conversationId, string $text, array $attachments = []): string
{
    return $store->storeUserMessage($conversationId, 'App\\Models\\User', '7', 'App\\Agents\\Chat', new UserMessage($text, $attachments));
}

it('encrypts message content at rest and decrypts it on read', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    storeUserTurn($store, $conversationId, 'the user secret');
    $messageId = $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt('the user secret'), conversationsResponse('the assistant secret'));

    expect($messageId)->toBeString();

    $rows = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->content)->not->toBe('the user secret')
        ->and($rows[1]->content)->not->toBe('the assistant secret')
        ->and(Crypt::decryptString($rows[0]->content))->toBe('the user secret')
        ->and(Crypt::decryptString($rows[1]->content))->toBe('the assistant secret')
        // 1.0 replays assistant text from steps — sealed like content.
        ->and($rows[1]->steps)->not->toContain('the assistant secret')
        ->and(ConversationContent::revealJson($rows[1]->steps)[0]['content'])->toBe('the assistant secret')
        ->and($rows[1]->status)->toBe(MessageStatus::Completed->value);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role->value)->toBe('user')
        ->and($messages[0]->content)->toBe('the user secret')
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->content)->toBe('the assistant secret');
});

it('stores content-only steps and empty traces when traces are opted out', function () {
    config()->set('ai-kit.conversations.persist_tool_traces', false);

    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    storeUserTurn($store, $conversationId, 'with attachment', [new RemoteDocument('https://example.test/cv.pdf', 'application/pdf')]);
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse('the answer', withTools: true));

    [$userRow, $assistantRow] = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($userRow->attachments)->toBe('[]')
        ->and($userRow->steps)->toBe('[]')
        ->and($assistantRow->usage)->toBe('[]')
        ->and($assistantRow->meta)->toBe('[]')
        ->and($assistantRow->steps)->not->toContain('the answer')
        ->and(ConversationContent::revealJson($assistantRow->steps))->toHaveCount(1)
        ->and(ConversationContent::revealJson($assistantRow->steps)[0])->toMatchArray(['content' => 'the answer', 'tool_calls' => []]);

    expect($store->getLatestConversationMessages($conversationId, 10)->last()->content)->toBe('the answer');
});

it('persists tool traces encrypted in steps, usage plaintext, and reconstructs the turn on read', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse('traced reply', withTools: true));

    $row = DB::table('agent_conversation_messages')->sole();

    expect($row->steps)->not->toContain('call_1')
        ->and($row->steps)->not->toContain('tool output')
        ->and(Crypt::decryptString($row->steps))->toContain('call_1')
        ->and(Crypt::decryptString($row->steps))->toContain('tool output')
        ->and($row->meta)->not->toContain('test/model')
        ->and(ConversationContent::revealJson($row->meta))->toMatchArray(['provider' => 'openrouter', 'model' => 'test/model'])
        ->and(json_decode($row->usage, true))->toMatchArray(['input_tokens' => 10, 'output_tokens' => 5])
        ->and(Crypt::decryptString($row->content))->toBe('traced reply');

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    // A stepless response stores ONE step: its text and its call together.
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('traced reply')
        ->and($messages[0]->toolCalls->first()->id)->toBe('call_1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->first()->result)->toBe('tool output');
});

it('encrypts persisted user attachments and rehydrates them on read', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    storeUserTurn($store, $conversationId, 'look at this', [new RemoteDocument('https://example.test/private-cv.pdf', 'application/pdf')]);
    storeUserTurn($store, $conversationId, 'and nothing else');

    [$withFile, $withoutFile] = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($withFile->attachments)->not->toContain('private-cv')
        // The empty marker stays plaintext so emptiness checks keep working.
        ->and($withoutFile->attachments)->toBe('[]');

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content)->toBe('look at this')
        ->and($messages[0]->attachments->first())->toBeInstanceOf(RemoteDocument::class)
        ->and($messages[0]->attachments->first()->url)->toBe('https://example.test/private-cv.pdf')
        ->and($messages[1]->content)->toBe('and nothing else');
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
        'steps' => json_encode([['content' => 'stored before encryption', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]),
        'usage' => '[]',
        'meta' => '{"provider":"openrouter"}',
        'status' => 'completed',
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
    $store->storeUserMessage($conversationId, null, 'telegram:123456789', 'App\\Agents\\Chat', new UserMessage('anonymous message'));

    $row = DB::table('agent_conversations')->sole();

    expect($row->participant_id)->toBe('telegram:123456789')
        ->and($row->participant_type)->toBeNull()
        ->and($store->conversationBelongsTo($conversationId, null, 'telegram:123456789'))->toBeTrue()
        ->and($store->conversationBelongsTo($conversationId, null, 'telegram:1'))->toBeFalse()
        ->and($store->getLatestConversationMessages($conversationId, 5)->first()->content)->toBe('anonymous message');
});

it('scopes the latest conversation to the agent', function () {
    $store = encryptedStore();

    $first = $store->storeConversation('App\\Models\\User', '7', 'Chat');
    $store->storeUserMessage($first, 'App\\Models\\User', '7', 'App\\Agents\\Chat', new UserMessage('hi chat'));
    $second = $store->storeConversation('App\\Models\\User', '7', 'Tutor');
    $store->storeUserMessage($second, 'App\\Models\\User', '7', 'App\\Agents\\Tutor', new UserMessage('hi tutor'));

    expect($store->latestConversationId('App\\Models\\User', '7', 'App\\Agents\\Chat'))->toBe($first)
        ->and($store->latestConversationId('App\\Models\\User', '7', 'App\\Agents\\Tutor'))->toBe($second);
});

it('honors an explicit persistToolTraces constructor override', function () {
    config()->set('ai-kit.conversations.persist_tool_traces', false);

    $store = new EncryptedConversationStore(persistToolTraces: true);

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt(), conversationsResponse(withTools: true));

    expect(Crypt::decryptString(DB::table('agent_conversation_messages')->sole()->steps))->toContain('call_1');
});

it('hands out decrypted rows from paginateConversationMessages', function () {
    $store = encryptedStore();

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'My chat');
    storeUserTurn($store, $conversationId, 'page me', [new RemoteDocument('https://example.test/a.pdf')]);
    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', conversationsPrompt('page me'), conversationsResponse('paged reply', withTools: true));

    [$assistant, $user] = $store->paginateConversationMessages($conversationId)->items();

    expect($user->content)->toBe('page me')
        ->and($user->attachments[0]['url'])->toBe('https://example.test/a.pdf')
        ->and($assistant->content)->toBe('paged reply')
        ->and($assistant->meta)->toMatchArray(['provider' => 'openrouter'])
        ->and($assistant->toolResults()[0])->toMatchArray(['id' => 'call_1', 'result' => 'tool output'])
        ->and($assistant->usage)->toMatchArray(['input_tokens' => 10]);
});

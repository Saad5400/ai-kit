<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Tests\Support\DeleteWidgetTool;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingApprovalAgent;

/**
 * Full turns through the bound EncryptedConversationStore on laravel/ai 1.0:
 * a real agent, the kit's OpenRouter gateway, and a faked HTTP provider.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    DeleteWidgetTool::$invocations = 0;

    // One fake for the whole test, fed from a queue: a second Http::fake()
    // would sit behind the first one's exhausted sequence.
    $GLOBALS['widgetReplies'] = [];
    Http::fake(fn () => array_shift($GLOBALS['widgetReplies']) ?? throw new OutOfBoundsException('No reply queued.'));
});

function widgetReplies(mixed ...$replies): void
{
    foreach ($replies as $reply) {
        $GLOBALS['widgetReplies'][] = is_array($reply) ? Http::response($reply) : $reply;
    }
}

function widgetCall(string $id, string $tool, array $arguments = ['id' => 4]): array
{
    return ['id' => $id, 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($arguments)]];
}

function widgetUser(): object
{
    return (object) ['id' => 7];
}

function widgetAssistantRows(): Collection
{
    return DB::table('agent_conversation_messages')->where('role', 'assistant')->orderBy('id')->get();
}

/**
 * Every column that can carry user content is ciphertext: nothing readable
 * from the conversation appears in the raw row.
 */
function expectSealed(object $row, array $secrets): void
{
    foreach (['content', 'steps', 'meta', 'attachments'] as $column) {
        foreach ($secrets as $secret) {
            expect((string) $row->{$column})->not->toContain($secret);
        }
    }
}

function pauseWidgetTurn(): string
{
    widgetReplies(
        OpenRouterSse::completion('', toolCalls: [widgetCall('call_look', 'LookupWidget')]),
        OpenRouterSse::completion('Deleting it now.', toolCalls: [widgetCall('call_del', 'DeleteWidget')]),
    );

    $paused = (new RememberingApprovalAgent)->forUser(widgetUser())
        ->prompt('please delete widget 4', provider: 'openrouter', model: 'test/model');

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pendingApprovals->pluck('id')->all())->toBe(['call_del']);

    return $paused->conversationId;
}

it('binds the encrypted store the turns below run through', function () {
    expect(app(ConversationStore::class))->toBeInstanceOf(EncryptedConversationStore::class);
});

it('pauses a turn on a gated tool with every trace sealed at rest', function () {
    $conversationId = pauseWidgetTurn();

    $user = DB::table('agent_conversation_messages')->where('role', 'user')->sole();
    [$assistant] = widgetAssistantRows();

    expect(widgetAssistantRows())->toHaveCount(1)
        ->and($assistant->status)->toBe(MessageStatus::Paused->value)
        ->and(ConversationContent::reveal($user->content))->toBe('please delete widget 4');

    expectSealed($user, ['delete widget']);
    expectSealed($assistant, ['secret sprocket', 'LookupWidget', 'DeleteWidget', 'call_del', 'Deleting it', 'destructive', 'test/model']);

    $steps = ConversationContent::revealJson($assistant->steps);

    expect($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call_look', 'name' => 'LookupWidget', 'result' => 'widget 4 is the secret sprocket'])
        ->and($steps[1]['content'])->toBe('Deleting it now.')
        ->and($steps[1]['tool_calls'][0])->toMatchArray(['id' => 'call_del', 'approval_reason' => 'destructive'])
        ->and($steps[1]['tool_calls'][0])->not->toHaveKey('result')
        // Usage is aggregate numbers only — it stays queryable plaintext.
        ->and(json_decode($assistant->usage, true))->toHaveKey('input_tokens');

    $pending = app(ConversationStore::class)->pendingApprovalsFor($conversationId);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->id)->toBe('call_del')
        ->and($pending[0]->tool)->toBe('DeleteWidget')
        ->and($pending[0]->arguments)->toBe(['id' => 4])
        ->and($pending[0]->reason)->toBe('destructive')
        ->and((new StoredApprovals)->pending($conversationId)->pluck('id')->all())->toBe(['call_del']);
});

it('resumes an approved pause and folds it into the message it paused on', function () {
    $conversationId = pauseWidgetTurn();
    $pausedId = widgetAssistantRows()->sole()->id;

    widgetReplies(OpenRouterSse::completion('Deleted widget 4.'));

    $resumed = (new RememberingApprovalAgent)->continue($conversationId, widgetUser())
        ->prompt(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->toBe('Deleted widget 4.')
        ->and(DeleteWidgetTool::$invocations)->toBe(1);

    // The resume replayed the decrypted history: the auto-run lookup's
    // result reached the provider as a tool message.
    Http::assertSent(fn ($request) => collect($request->data()['messages'] ?? [])
        ->contains(fn (array $message) => $message['role'] === 'tool' && str_contains((string) json_encode($message), 'secret sprocket')));

    $row = widgetAssistantRows()->sole();

    expect($row->id)->toBe($pausedId)
        ->and($row->status)->toBe(MessageStatus::Completed->value)
        ->and(ConversationContent::reveal($row->content))->toBe('Deleted widget 4.');

    expectSealed($row, ['deleted widget', 'Deleted widget', 'secret sprocket', 'DeleteWidget']);

    $calls = collect(ConversationContent::revealJson($row->steps))->flatMap(fn (array $step) => $step['tool_calls'])->keyBy('id');

    expect(ConversationContent::revealJson($row->steps))->toHaveCount(3)
        ->and($calls['call_del'])->toMatchArray(['result' => 'deleted widget 4', 'approval_reason' => 'destructive'])
        ->and(app(ConversationStore::class)->pendingApprovalsFor($conversationId))->toBe([])
        ->and((new StoredApprovals)->pending($conversationId))->toBeEmpty();

    // The next turn reads the whole folded history back out of ciphertext.
    widgetReplies(OpenRouterSse::completion('You are welcome.'));

    (new RememberingApprovalAgent)->continue($conversationId, widgetUser())
        ->prompt('thanks', provider: 'openrouter', model: 'test/model');

    Http::assertSent(function ($request) {
        $messages = collect($request->data()['messages'] ?? [])->reject(fn (array $m) => $m['role'] === 'system')->values();

        return $messages->pluck('role')->all() === ['user', 'assistant', 'tool', 'assistant', 'tool', 'assistant', 'user']
            && $messages[0]['content'] === 'please delete widget 4'
            && str_contains((string) json_encode($messages[4]), 'deleted widget 4')
            && $messages[5]['content'] === 'Deleted widget 4.';
    });
});

// Stock 1.0 behaviour, kept: a resume that dies — here on decisions naming
// no pending call — is recorded by failing the paused turn IN PLACE.
it('refuses decisions for a call that is not pending, failing the pause in place', function () {
    $conversationId = pauseWidgetTurn();

    widgetReplies(OpenRouterSse::completion('nope'));

    expect(fn () => (new RememberingApprovalAgent)->continue($conversationId, widgetUser())
        ->prompt(Decisions::from(['call_nope' => true]), provider: 'openrouter', model: 'test/model'))
        ->toThrow(ApprovalMismatchException::class);

    $row = widgetAssistantRows()->sole();

    expect(DeleteWidgetTool::$invocations)->toBe(0)
        ->and($row->status)->toBe(MessageStatus::Failed->value)
        ->and(ConversationContent::revealJson($row->meta)['error'])->toContain('do not match');

    expectSealed($row, ['do not match', 'secret sprocket']);
});

it('records a run that throws as a failed turn with its error, sealed', function () {
    widgetReplies(
        OpenRouterSse::completion('', toolCalls: [widgetCall('call_look', 'LookupWidget')]),
        Http::response(['error' => ['message' => 'upstream exploded']], 400),
    );

    expect(fn () => (new RememberingApprovalAgent)->forUser(widgetUser())
        ->prompt('look up widget 4', provider: 'openrouter', model: 'test/model'))
        ->toThrow(Exception::class);

    $row = widgetAssistantRows()->sole();
    $meta = ConversationContent::revealJson($row->meta);

    expect($row->status)->toBe(MessageStatus::Failed->value)
        ->and($meta['error'] ?? '')->not->toBe('')
        ->and(ConversationContent::revealJson($row->steps)[0]['tool_calls'][0])
        ->toMatchArray(['id' => 'call_look', 'result' => 'widget 4 is the secret sprocket']);

    expectSealed($row, ['secret sprocket', 'LookupWidget', (string) $meta['error']]);
});

it('keeps a content-only turn replayable with traces off, the pause unresumable', function () {
    config()->set('ai-kit.conversations.persist_tool_traces', false);

    $conversationId = pauseWidgetTurn();

    $row = widgetAssistantRows()->sole();

    expect(ConversationContent::revealJson($row->steps))->toBe([[
        'content' => 'Deleting it now.',
        'tool_calls' => [],
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ]])
        ->and($row->meta)->toBe('[]')
        ->and($row->usage)->toBe('[]')
        ->and(app(ConversationStore::class)->pendingApprovalsFor($conversationId))->toBe([]);

    expectSealed($row, ['Deleting it', 'secret sprocket']);

    widgetReplies(OpenRouterSse::completion('ok'));

    expect(fn () => (new RememberingApprovalAgent)->continue($conversationId, widgetUser())
        ->prompt(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model'))
        ->toThrow(ApprovalMismatchException::class);

    expect(DeleteWidgetTool::$invocations)->toBe(0);
});

function widgetToolStream(string $id, string $tool): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['role' => 'assistant', 'content' => 'On it. ']),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => $id, 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => '{"id":4}']]]], finishReason: 'tool_calls'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5]),
    ]);
}

function widgetTextStream(string $text): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['role' => 'assistant', 'content' => $text], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5]),
    ]);
}

it('streams a pause and a resume through the same sealed row', function () {
    widgetReplies(Http::response(widgetToolStream('call_del', 'DeleteWidget')));

    $stream = (new RememberingApprovalAgent)->forUser(widgetUser())
        ->stream('please delete widget 4', provider: 'openrouter', model: 'test/model');

    foreach ($stream as $event) {
        //
    }

    $conversationId = $stream->conversationId;
    $paused = widgetAssistantRows()->sole();

    expect($paused->status)->toBe(MessageStatus::Paused->value)
        ->and(app(ConversationStore::class)->pendingApprovalsFor($conversationId)[0]->id)->toBe('call_del');

    expectSealed($paused, ['DeleteWidget', 'On it', 'destructive']);

    widgetReplies(Http::response(widgetTextStream('Deleted widget 4.')));

    $resumed = (new RememberingApprovalAgent)->continue($conversationId, widgetUser())
        ->stream(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model');

    foreach ($resumed as $event) {
        //
    }

    $row = widgetAssistantRows()->sole();

    expect($row->id)->toBe($paused->id)
        ->and($row->status)->toBe(MessageStatus::Completed->value)
        ->and(DeleteWidgetTool::$invocations)->toBe(1)
        ->and(collect(ConversationContent::revealJson($row->steps))->flatMap(fn (array $step) => $step['tool_calls'])->firstWhere('id', 'call_del')['result'])
        ->toBe('deleted widget 4');

    expectSealed($row, ['deleted widget', 'Deleted widget', 'DeleteWidget']);
});

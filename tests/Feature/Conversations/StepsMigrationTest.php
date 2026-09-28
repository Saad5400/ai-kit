<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\StepsBackfill;
use Saad\AiKit\Tests\Support\DeleteWidgetTool;
use Saad\AiKit\Tests\Support\LegacyConversationRows as Legacy;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingApprovalAgent;

/**
 * The 0.10 → 1.0 steps migration against rows shaped exactly as the kit's
 * 0.10 encrypted store wrote them: the history must read back the same, a
 * pending approval must survive as a resumable pause, and nothing may land
 * at rest in plaintext.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    DeleteWidgetTool::$invocations = 0;

    Legacy::rewindSchema();
});

/**
 * Seed one conversation of every 0.10 shape; returns their ids.
 *
 * @return array<string, string>
 */
function seedLegacyConversations(): array
{
    // A traced, completed tool turn — reasoning and citations in meta.
    $traced = Legacy::conversation('Traced');
    Legacy::row($traced, 'user', 'find widget 4');
    Legacy::row($traced, 'assistant', 'Found it: the sprocket.', [
        'tool_calls' => [Legacy::call('call_1', 'LookupWidget') + ['reasoning_id' => 'rs_1', 'reasoning_encrypted_content' => 'opaque']],
        'tool_results' => [Legacy::result('call_1', 'LookupWidget', 'widget 4 is the secret sprocket')],
        'meta' => [
            'provider' => 'openrouter',
            'model' => 'test/model',
            'citations' => [['type' => 'url', 'url' => 'https://example.test/source', 'title' => 'Source']],
            'reasoning' => 'I should look it up first.',
        ],
    ]);

    // A turn paused on a gated call, after an auto-run call answered.
    $paused = Legacy::conversation('Paused');
    Legacy::row($paused, 'user', 'please delete widget 4');
    Legacy::row($paused, 'assistant', 'Deleting it now.', [
        'tool_calls' => [Legacy::call('call_look', 'LookupWidget'), Legacy::call('call_del', 'DeleteWidget')],
        'tool_results' => [Legacy::result('call_look', 'LookupWidget', 'widget 4 is the secret sprocket')],
        'approval_state' => ['pending' => ['call_del' => 'destructive']],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => [], 'provider_content_blocks' => [['type' => 'opaque']]],
    ]);

    // A pause 0.10 already resolved (results merged, pending emptied) plus
    // its separate resume row; then a call whose result landed on a LATER
    // row, and a dangling call nothing ever answered.
    $resolved = Legacy::conversation('Resolved');
    Legacy::row($resolved, 'user', 'delete widget 9');
    Legacy::row($resolved, 'assistant', '', [
        'tool_calls' => [Legacy::call('call_x', 'DeleteWidget', ['id' => 9])],
        'tool_results' => [Legacy::result('call_x', 'DeleteWidget', 'deleted widget 9', ['id' => 9])],
        'approval_state' => ['pending' => []],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);
    Legacy::row($resolved, 'assistant', 'Done, widget 9 is gone.', ['meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []]]);
    Legacy::row($resolved, 'user', 'and widget 10?');
    Legacy::row($resolved, 'assistant', '', [
        'tool_calls' => [Legacy::call('call_y', 'LookupWidget', ['id' => 10]), Legacy::call('call_z', 'LookupWidget', ['id' => 11])],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);
    Legacy::row($resolved, 'assistant', 'Widget 10 is fine.', [
        'tool_results' => [Legacy::result('call_y', 'LookupWidget', 'widget 10 ok', ['id' => 10])],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);

    // Written with traces off: text only, every trace column empty.
    $untraced = Legacy::conversation('Untraced');
    Legacy::row($untraced, 'user', 'hi there');
    Legacy::row($untraced, 'assistant', 'hello, private answer', ['usage' => []]);

    // Written before encryption was enabled at all.
    $plaintext = Legacy::conversation('Plaintext');
    Legacy::row($plaintext, 'user', 'an old question', encrypt: false);
    Legacy::row($plaintext, 'assistant', 'an old plaintext answer', ['meta' => ['provider' => 'openrouter', 'model' => 'test/model']], encrypt: false);

    return compact('traced', 'paused', 'resolved', 'untraced', 'plaintext');
}

function history(string $conversationId): array
{
    return Legacy::transcript(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 100));
}

it('adds steps and status, keeps the 0.10 columns, and rebuilds the participant index', function () {
    seedLegacyConversations();
    $legacyBefore = DB::table(Legacy::MESSAGES)->orderBy('id')->get(['id', 'tool_calls', 'tool_results', 'approval_state']);

    Legacy::migrate();

    $index = collect(Schema::getIndexes(Legacy::MESSAGES))->firstWhere('name', 'participant_index');

    expect(Schema::hasColumns(Legacy::MESSAGES, ['steps', 'status', 'tool_calls', 'tool_results', 'approval_state']))->toBeTrue()
        ->and($index['columns'])->toBe(['participant_type', 'participant_id', 'agent'])
        ->and(DB::table(Legacy::MESSAGES)->whereNull('steps')->count())->toBe(0)
        ->and(DB::table(Legacy::MESSAGES)->where('role', 'user')->pluck('steps')->unique()->all())->toBe(['[]'])
        // Old workers mid-deploy still read their columns, untouched.
        ->and(DB::table(Legacy::MESSAGES)->orderBy('id')->get(['id', 'tool_calls', 'tool_results', 'approval_state']))->toEqual($legacyBefore);

    // The 1.0 store inserts without the 0.10 columns: they are nullable now.
    $conversationId = Legacy::conversation();
    app(ConversationStore::class)->storeUserMessage($conversationId, Legacy::OWNER_TYPE, Legacy::OWNER_ID, RememberingApprovalAgent::class, new UserMessage('new row'));

    expect(DB::table(Legacy::MESSAGES)->where('conversation_id', $conversationId)->sole()->tool_calls)->toBeNull();
});

it('reads the migrated history back exactly as the 0.10 store replayed it', function () {
    $ids = seedLegacyConversations();

    Legacy::migrate();

    expect(history($ids['traced']))->toBe([
        ['user' => 'find widget 4'],
        ['assistant' => '', 'calls' => ['call_1']],
        ['tool' => [['call_1', 'widget 4 is the secret sprocket']]],
        ['assistant' => 'Found it: the sprocket.', 'calls' => []],
    ])
        ->and(history($ids['resolved']))->toBe([
            ['user' => 'delete widget 9'],
            ['assistant' => '', 'calls' => ['call_x']],
            ['tool' => [['call_x', 'deleted widget 9']]],
            ['assistant' => 'Done, widget 9 is gone.', 'calls' => []],
            ['user' => 'and widget 10?'],
            // call_y's result sat on the NEXT row; the dangling call_z is dropped.
            ['assistant' => '', 'calls' => ['call_y']],
            ['tool' => [['call_y', 'widget 10 ok']]],
            ['assistant' => 'Widget 10 is fine.', 'calls' => []],
        ])
        ->and(history($ids['untraced']))->toBe([
            ['user' => 'hi there'],
            ['assistant' => 'hello, private answer', 'calls' => []],
        ])
        ->and(history($ids['plaintext']))->toBe([
            ['user' => 'an old question'],
            ['assistant' => 'an old plaintext answer', 'calls' => []],
        ]);
});

it('moves reasoning onto the step, drops provider blocks and reasoning_* keys, keeps provider, model and citations', function () {
    $ids = seedLegacyConversations();

    Legacy::migrate();

    $row = DB::table(Legacy::MESSAGES)->where('conversation_id', $ids['traced'])->where('role', 'assistant')->sole();
    $steps = ConversationContent::revealJson($row->steps);
    $meta = ConversationContent::revealJson($row->meta);

    expect($row->status)->toBe(MessageStatus::Completed->value)
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]['tool_calls'][0])->toBe([
            'id' => 'call_1',
            'name' => 'LookupWidget',
            'arguments' => ['id' => 4],
            'result_id' => null,
            'result' => 'widget 4 is the secret sprocket',
        ])
        ->and($steps[1])->toMatchArray(['content' => 'Found it: the sprocket.', 'reasoning' => 'I should look it up first.', 'tool_calls' => []])
        ->and($meta)->toBe([
            'provider' => 'openrouter',
            'model' => 'test/model',
            'citations' => [['type' => 'url', 'url' => 'https://example.test/source', 'title' => 'Source']],
        ]);

    $pausedMeta = ConversationContent::revealJson(DB::table(Legacy::MESSAGES)->where('conversation_id', $ids['paused'])->where('role', 'assistant')->sole()->meta);

    expect($pausedMeta)->not->toHaveKey('provider_content_blocks');
});

it('keeps a pending approval as a paused turn the store resolves', function () {
    $ids = seedLegacyConversations();

    Legacy::migrate();

    $row = DB::table(Legacy::MESSAGES)->where('conversation_id', $ids['paused'])->where('role', 'assistant')->sole();
    $steps = ConversationContent::revealJson($row->steps);

    expect($row->status)->toBe(MessageStatus::Paused->value)
        // Text and calls in ONE step: 1.0 resumes from the latest assistant message's calls.
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]['content'])->toBe('Deleting it now.')
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call_look', 'result' => 'widget 4 is the secret sprocket'])
        ->and($steps[0]['tool_calls'][1])->toMatchArray(['id' => 'call_del', 'approval_reason' => 'destructive'])
        ->and($steps[0]['tool_calls'][1])->not->toHaveKey('result');

    $pending = app(ConversationStore::class)->pendingApprovalsFor($ids['paused']);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->toArray())->toBe(['id' => 'call_del', 'tool' => 'DeleteWidget', 'arguments' => ['id' => 4], 'reason' => 'destructive'])
        ->and((new StoredApprovals)->pending($ids['paused'])->pluck('id')->all())->toBe(['call_del'])
        // 0.10's resolved pause (pending emptied) is a completed turn now.
        ->and(app(ConversationStore::class)->pendingApprovalsFor($ids['resolved']))->toBe([])
        ->and(DB::table(Legacy::MESSAGES)->where('status', MessageStatus::Paused->value)->count())->toBe(1);
});

it('resumes a migrated pause end to end, folding the resume into the paused row', function () {
    $ids = seedLegacyConversations();

    Legacy::migrate();

    $pausedId = DB::table(Legacy::MESSAGES)->where('conversation_id', $ids['paused'])->where('role', 'assistant')->sole()->id;

    Http::fake(['*' => Http::response(OpenRouterSse::completion('Deleted widget 4.'))]);

    $resumed = (new RememberingApprovalAgent)
        ->continue($ids['paused'], (object) ['id' => (int) Legacy::OWNER_ID])
        ->prompt(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model');

    expect($resumed->text)->toBe('Deleted widget 4.')
        ->and(DeleteWidgetTool::$invocations)->toBe(1);

    // The provider saw the migrated history: both calls, both results in one answering message.
    Http::assertSent(function ($request) {
        $messages = collect($request->data()['messages'])->reject(fn (array $m) => $m['role'] === 'system')->values();

        return $messages->pluck('role')->all() === ['user', 'assistant', 'tool', 'tool']
            && $messages[0]['content'] === 'please delete widget 4'
            && collect($messages[1]['tool_calls'])->pluck('id')->all() === ['call_look', 'call_del']
            && str_contains(json_encode($messages[2]), 'secret sprocket')
            && str_contains(json_encode($messages[3]), 'deleted widget 4');
    });

    $row = DB::table(Legacy::MESSAGES)->where('conversation_id', $ids['paused'])->where('role', 'assistant')->sole();
    $calls = collect(ConversationContent::revealJson($row->steps))->flatMap(fn (array $step) => $step['tool_calls'])->keyBy('id');

    expect($row->id)->toBe($pausedId)
        ->and($row->status)->toBe(MessageStatus::Completed->value)
        ->and(ConversationContent::reveal($row->content))->toBe('Deleted widget 4.')
        ->and($calls['call_del']['result'])->toBe('deleted widget 4')
        ->and(app(ConversationStore::class)->pendingApprovalsFor($ids['paused']))->toBe([]);
});

it('seals every converted row the way the encrypted store writes it', function () {
    seedLegacyConversations();

    Legacy::migrate();

    $secrets = ['secret sprocket', 'LookupWidget', 'DeleteWidget', 'I should look it up', 'example.test', 'Deleting it',
        'private answer', 'widget 9', 'widget 10', 'plaintext answer', 'test/model'];

    foreach (DB::table(Legacy::MESSAGES)->where('role', 'assistant')->get() as $row) {
        expect($row->steps)->not->toBe('[]');

        // Ciphertext: it decrypts, and nothing readable sits in the column.
        expect(fn () => Crypt::decryptString($row->steps))->not->toThrow(Exception::class);

        foreach ($secrets as $secret) {
            expect($row->steps)->not->toContain($secret)
                ->and($row->meta)->not->toContain($secret);
        }
    }
});

it('is idempotent, and the command converts rows an old worker wrote after the migration', function () {
    $ids = seedLegacyConversations();

    Legacy::migrate();

    $before = DB::table(Legacy::MESSAGES)->orderBy('id')->pluck('steps', 'id');

    expect(StepsBackfill::configured()->run())->toMatchObject(['written' => 0, 'reconciled' => 0, 'undecryptable' => []]);

    Legacy::migrate();

    expect(DB::table(Legacy::MESSAGES)->orderBy('id')->pluck('steps', 'id'))->toEqual($before);

    // An old (0.10) worker keeps inserting its own shape mid-deploy.
    Legacy::row($ids['untraced'], 'user', 'one more');
    Legacy::row($ids['untraced'], 'assistant', 'written by an old worker', [
        'tool_calls' => [Legacy::call('call_late', 'LookupWidget')],
        'tool_results' => [Legacy::result('call_late', 'LookupWidget', 'late result')],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);

    $this->artisan('ai-kit:backfill-conversation-steps')
        ->expectsOutput('Converted 2 conversation messages onto steps.')
        ->assertSuccessful();

    expect(array_slice(history($ids['untraced']), 2))->toBe([
        ['user' => 'one more'],
        ['assistant' => '', 'calls' => ['call_late']],
        ['tool' => [['call_late', 'late result']]],
        ['assistant' => 'written by an old worker', 'calls' => []],
    ]);

    $this->artisan('ai-kit:backfill-conversation-steps')
        ->expectsOutput('Every conversation message already has steps.')
        ->assertSuccessful();
});

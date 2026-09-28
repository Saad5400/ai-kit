<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\Events\ConversationsPruning;
use Saad\AiKit\Conversations\StoredSteps;

uses(RefreshDatabase::class);

function prunableConversation(int $idleDays, int $messages = 1): string
{
    $id = (string) Str::uuid7();
    $timestamp = now()->subDays($idleDays);

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => null,
        'participant_id' => 'session-'.$id,
        'title' => 'A chat',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    for ($i = 0; $i < $messages; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $id,
            'participant_type' => null,
            'participant_id' => 'session-'.$id,
            'agent' => 'App\\TestAgent',
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => 'message '.$i,
            'attachments' => '[]',
            'steps' => $i % 2 === 0 ? '[]' : json_encode([StoredSteps::step('message '.$i)]),
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    return $id;
}

it('prunes idle conversations and their messages past --days, keeping fresh ones', function () {
    Event::fake([ConversationsPruning::class]);

    $stale = prunableConversation(idleDays: 10, messages: 2);
    $fresh = prunableConversation(idleDays: 2);

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])
        ->expectsOutputToContain('Pruned 1 conversations (2 messages)')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe([$fresh])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $stale)->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $fresh)->count())->toBe(1);

    Event::assertDispatched(
        ConversationsPruning::class,
        fn (ConversationsPruning $event) => $event->conversationIds === [$stale],
    );
});

it('defaults the window to ai-kit.conversations.retention_days', function () {
    Event::fake([ConversationsPruning::class]);
    config()->set('ai-kit.conversations.retention_days', 3);

    $stale = prunableConversation(idleDays: 5);
    prunableConversation(idleDays: 1);

    $this->artisan('ai-kit:prune-conversations')->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(1)
        ->and(DB::table('agent_conversations')->where('id', $stale)->exists())->toBeFalse();

    Event::assertDispatched(ConversationsPruning::class);
});

it('prunes nothing by default — retention is forever until an app sets a window', function () {
    Event::fake([ConversationsPruning::class]);

    prunableConversation(idleDays: 400);

    $this->artisan('ai-kit:prune-conversations')
        ->expectsOutputToContain('Retention is forever')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(1);

    Event::assertNotDispatched(ConversationsPruning::class);
});

it('empties the 0.10 trace columns past the trace window while the conversation lives on — even with retention forever', function () {
    $conversationId = prunableConversation(idleDays: 0);

    $oldTraced = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $oldTraced,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => 'session-x',
        'agent' => 'App\\Agent',
        'role' => 'assistant',
        'content' => 'kept content',
        'attachments' => '[]',
        'tool_calls' => '[{"id":"call_1"}]',
        'tool_results' => '[{"id":"call_1"}]',
        'usage' => '{"prompt_tokens":10}',
        'meta' => '{"provider":"openrouter"}',
        'approval_state' => '{"pending":{"call_1":null}}',
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    $freshTraced = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $freshTraced,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => 'session-x',
        'agent' => 'App\\Agent',
        'role' => 'assistant',
        'content' => 'fresh content',
        'attachments' => '[]',
        'tool_calls' => '[{"id":"call_2"}]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->expectsOutputToContain('Stripped tool traces from 1 messages')
        ->assertSuccessful();

    $old = DB::table('agent_conversation_messages')->where('id', $oldTraced)->sole();
    $fresh = DB::table('agent_conversation_messages')->where('id', $freshTraced)->sole();

    expect($old->content)->toBe('kept content')
        ->and($old->tool_calls)->toBe('[]')
        ->and($old->tool_results)->toBe('[]')
        ->and($old->meta)->toBe('[]')
        ->and($old->approval_state)->toBeNull()
        ->and($old->usage)->toBe('{"prompt_tokens":10}')
        ->and($fresh->tool_calls)->toBe('[{"id":"call_2"}]')
        ->and(DB::table('agent_conversations')->count())->toBe(1);
});

function tracedStepsRow(string $conversationId, int $ageDays, string $status = 'completed'): string
{
    // uuid7 ids sort by time; spacing inserts keeps "newest row" unambiguous.
    usleep(1000);
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => 'session-x',
        'agent' => 'App\\Agent',
        'role' => 'assistant',
        'content' => Crypt::encryptString('Done: widget deleted.'),
        'attachments' => '[]',
        'steps' => Crypt::encryptString(json_encode([
            StoredSteps::step('Let me check.', [['id' => 'call_1', 'name' => 'LookupWidget', 'arguments' => ['id' => 4], 'result' => 'secret sprocket']], 'private reasoning'),
            StoredSteps::step('Done: widget deleted.', $status === 'paused'
                ? [['id' => 'call_2', 'name' => 'DeleteWidget', 'arguments' => ['id' => 4], 'approval_reason' => 'destructive']]
                : []),
        ])),
        'usage' => '{"input_tokens":10}',
        'meta' => Crypt::encryptString('{"provider":"openrouter","model":"test/model"}'),
        'status' => $status,
        'created_at' => now()->subDays($ageDays),
        'updated_at' => now()->subDays($ageDays),
    ]);

    return $id;
}

it('strips traces out of sealed steps past the trace window, keeping the text and re-sealing it', function () {
    $conversationId = prunableConversation(idleDays: 0);

    $old = tracedStepsRow($conversationId, ageDays: 30);
    $fresh = tracedStepsRow($conversationId, ageDays: 2);
    // The newest assistant row: still resumable.
    $paused = tracedStepsRow($conversationId, ageDays: 30, status: 'paused');
    $freshSteps = DB::table('agent_conversation_messages')->where('id', $fresh)->value('steps');

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->expectsOutputToContain('Stripped tool traces from 1 messages')
        ->assertSuccessful();

    $row = DB::table('agent_conversation_messages')->where('id', $old)->sole();

    expect(ConversationContent::revealJson($row->steps))->toBe([StoredSteps::step("Let me check.\n\nDone: widget deleted.")])
        ->and($row->steps)->not->toContain('Let me check')
        ->and($row->meta)->toBe('[]')
        ->and($row->usage)->toBe('{"input_tokens":10}')
        ->and($row->status)->toBe(MessageStatus::Completed->value)
        ->and(ConversationContent::reveal($row->content))->toBe('Done: widget deleted.')
        // The newest paused turn keeps what a resume needs; a fresh one is inside the window.
        ->and(ConversationContent::revealJson(DB::table('agent_conversation_messages')->where('id', $paused)->value('steps'))[1]['tool_calls'])->toHaveCount(1)
        ->and(DB::table('agent_conversation_messages')->where('id', $fresh)->value('steps'))->toBe($freshSteps);

    // The stripped row matches nothing: a second run rewrites nothing.
    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->doesntExpectOutputToContain('Stripped')
        ->assertSuccessful();
});

it('strips an abandoned pause but spares the newest paused row of each conversation', function () {
    // Conversation A: a pause the user walked away from, then a newer turn.
    $walkedAway = prunableConversation(idleDays: 0);
    $abandoned = tracedStepsRow($walkedAway, ageDays: 40, status: 'paused');
    $newer = tracedStepsRow($walkedAway, ageDays: 30);

    // Conversation B: its newest assistant row is still the pause — resumable.
    $waiting = prunableConversation(idleDays: 0);
    tracedStepsRow($waiting, ageDays: 40);
    $resumable = tracedStepsRow($waiting, ageDays: 30, status: 'paused');

    // Conversation C: two pauses, the older one superseded by the newer.
    $twice = prunableConversation(idleDays: 0);
    $superseded = tracedStepsRow($twice, ageDays: 40, status: 'paused');
    $latestPause = tracedStepsRow($twice, ageDays: 30, status: 'paused');

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->expectsOutputToContain('Stripped tool traces from 4 messages')
        ->assertSuccessful();

    $steps = fn (string $id): array => ConversationContent::revealJson(DB::table('agent_conversation_messages')->where('id', $id)->value('steps'));
    $contentOnly = [StoredSteps::step("Let me check.\n\nDone: widget deleted.")];

    foreach ([$abandoned, $newer, $superseded] as $id) {
        $row = DB::table('agent_conversation_messages')->where('id', $id)->sole();

        expect($steps($id))->toBe($contentOnly)
            ->and($row->meta)->toBe('[]')
            ->and($row->steps)->not->toContain('Let me check');
    }

    foreach ([$resumable, $latestPause] as $id) {
        expect($steps($id)[1]['tool_calls'][0])->toMatchArray(['id' => 'call_2', 'approval_reason' => 'destructive'])
            ->and(DB::table('agent_conversation_messages')->where('id', $id)->value('meta'))->not->toBe('[]');
    }

    // The abandoned pause keeps its status but no longer carries a pending call.
    expect(DB::table('agent_conversation_messages')->where('id', $abandoned)->value('status'))->toBe(MessageStatus::Paused->value)
        ->and(app(ConversationStore::class)->pendingApprovalsFor($twice)[0]->id)->toBe('call_2');

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->doesntExpectOutputToContain('Stripped')
        ->assertSuccessful();
});

it('strips a 1.0 row whose traces live only in steps, with meta and attachments empty', function () {
    $conversationId = prunableConversation(idleDays: 0);

    $stepsOnly = tracedStepsRow($conversationId, ageDays: 30);
    DB::table('agent_conversation_messages')->where('id', $stepsOnly)->update(['meta' => '[]']);

    // Already content-only (a stripped row, or one written with traces off): nothing to do.
    $contentOnly = tracedStepsRow($conversationId, ageDays: 30);
    DB::table('agent_conversation_messages')->where('id', $contentOnly)->update([
        'meta' => '[]',
        'steps' => $sealedContentOnly = Crypt::encryptString(json_encode([StoredSteps::step('Done: widget deleted.')])),
    ]);

    // Newest assistant row, so neither row above is spared as a resumable pause.
    tracedStepsRow($conversationId, ageDays: 1);

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->expectsOutputToContain('Stripped tool traces from 1 messages')
        ->assertSuccessful();

    $row = DB::table('agent_conversation_messages')->where('id', $stepsOnly)->sole();

    expect(ConversationContent::revealJson($row->steps))->toBe([StoredSteps::step("Let me check.\n\nDone: widget deleted.")])
        ->and(ConversationContent::looksEncrypted($row->steps))->toBeTrue()
        ->and($row->meta)->toBe('[]')
        // Untouched, not re-sealed: a rewrite would change the ciphertext.
        ->and(DB::table('agent_conversation_messages')->where('id', $contentOnly)->value('steps'))->toBe($sealedContentOnly);

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->doesntExpectOutputToContain('Stripped')
        ->assertSuccessful();
});

it('keeps an unconverted row\'s NULL steps for the backfill, and leaves undecryptable steps alone', function () {
    $conversationId = prunableConversation(idleDays: 0);

    $unconverted = tracedStepsRow($conversationId, ageDays: 30);
    DB::table('agent_conversation_messages')->where('id', $unconverted)->update(['steps' => null, 'tool_calls' => '[{"id":"call_1"}]']);

    $foreign = tracedStepsRow($conversationId, ageDays: 30);
    $foreignSteps = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('[{"content":"x"}]');
    DB::table('agent_conversation_messages')->where('id', $foreign)->update(['steps' => $foreignSteps]);

    tracedStepsRow($conversationId, ageDays: 1);

    $this->artisan('ai-kit:prune-conversations', ['--trace-days' => 14])
        ->expectsOutputToContain('Stripped tool traces from 1 messages')
        ->expectsOutputToContain('Left 1 messages untouched')
        ->assertSuccessful();

    $row = DB::table('agent_conversation_messages')->where('id', $unconverted)->sole();

    expect($row->steps)->toBeNull()
        ->and($row->tool_calls)->toBe('[]')
        ->and($row->meta)->toBe('[]')
        ->and(DB::table('agent_conversation_messages')->where('id', $foreign)->value('steps'))->toBe($foreignSteps)
        ->and(DB::table('agent_conversation_messages')->where('id', $foreign)->value('meta'))->not->toBe('[]');
});

it('defaults the trace window to ai-kit.conversations.trace_retention_days', function () {
    config()->set('ai-kit.conversations.trace_retention_days', 3);

    $conversationId = prunableConversation(idleDays: 0);

    DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->update(['meta' => Crypt::encryptString('{"provider":"openrouter"}'), 'created_at' => now()->subDays(5)]);

    $this->artisan('ai-kit:prune-conversations')->assertSuccessful();

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->sole()->meta)
        ->toBe('[]');
});

it('spares a conversation revived between the announcement and the delete, messages included', function () {
    $doomed = prunableConversation(idleDays: 10);
    $revived = prunableConversation(idleDays: 10, messages: 3);

    // The listener runs in exactly the window the race lives in: the ids are
    // read, and this thread gets a new message before the delete lands.
    Event::listen(ConversationsPruning::class, function () use ($revived) {
        DB::table('agent_conversations')->where('id', $revived)->update(['updated_at' => now()]);
    });

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])
        ->expectsOutputToContain('Pruned 1 conversations (1 messages)')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->pluck('id')->all())->toBe([$revived])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $revived)->count())->toBe(3)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $doomed)->count())->toBe(0);
});

it('works through the table in id-ordered chunks, announcing each one', function () {
    $announced = [];

    Event::listen(ConversationsPruning::class, function (ConversationsPruning $event) use (&$announced) {
        $announced[] = $event->conversationIds;
    });

    $ids = collect(range(1, 5))->map(fn () => prunableConversation(idleDays: 10))->sort()->values()->all();

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7, '--chunk' => 2])
        ->expectsOutputToContain('Pruned 5 conversations')
        ->assertSuccessful();

    expect($announced)->toBe([
        [$ids[0], $ids[1]],
        [$ids[2], $ids[3]],
        [$ids[4]],
    ])->and(DB::table('agent_conversations')->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(0);
});

it('deletes nothing and stays silent on the event when nothing is stale', function () {
    Event::fake([ConversationsPruning::class]);

    prunableConversation(idleDays: 2);

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])
        ->expectsOutputToContain('No conversations idle')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(1);

    Event::assertNotDispatched(ConversationsPruning::class);
});

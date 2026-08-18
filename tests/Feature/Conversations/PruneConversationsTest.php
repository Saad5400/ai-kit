<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Saad\AiKit\Conversations\Events\ConversationsPruning;

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
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'approval_state' => null,
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

it('strips tool traces past the trace window while the conversation lives on — even with retention forever', function () {
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

it('defaults the trace window to ai-kit.conversations.trace_retention_days', function () {
    config()->set('ai-kit.conversations.trace_retention_days', 3);

    $conversationId = prunableConversation(idleDays: 0);

    DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->update(['tool_calls' => '[{"id":"call_9"}]', 'created_at' => now()->subDays(5)]);

    $this->artisan('ai-kit:prune-conversations')->assertSuccessful();

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->sole()->tool_calls)
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

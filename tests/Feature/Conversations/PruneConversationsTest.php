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

it('deletes nothing and stays silent on the event when nothing is stale', function () {
    Event::fake([ConversationsPruning::class]);

    prunableConversation(idleDays: 2);

    $this->artisan('ai-kit:prune-conversations', ['--days' => 7])
        ->expectsOutputToContain('No conversations idle')
        ->assertSuccessful();

    expect(DB::table('agent_conversations')->count())->toBe(1);

    Event::assertNotDispatched(ConversationsPruning::class);
});

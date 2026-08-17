<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Saad\AiKit\Conversations\ConversationOwnership;

uses(RefreshDatabase::class);

function ownedConversation(?string $participantType, string $participantId): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $participantType,
        'participant_id' => $participantId,
        'title' => 'A chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('confirms ownership for a matching participant id', function () {
    $id = ownedConversation('App\\SessionOwner', 'session-abc');

    $ownership = app(ConversationOwnership::class);

    expect($ownership->owns($id, 'session-abc'))->toBeTrue()
        ->and($ownership->owns($id, 'session-other'))->toBeFalse()
        ->and($ownership->owns((string) Str::uuid7(), 'session-abc'))->toBeFalse();
});

it('filters by participant type when one is given', function () {
    $id = ownedConversation('App\\AdminOwner', '42');

    $ownership = app(ConversationOwnership::class);

    expect($ownership->owns($id, '42', 'App\\AdminOwner'))->toBeTrue()
        // The defect this helper centralizes away: an id-only check would
        // hand App\SessionOwner "42" someone else's conversation.
        ->and($ownership->owns($id, '42', 'App\\SessionOwner'))->toBeFalse();
});

it('matches any participant type when none is given', function () {
    $typeless = ownedConversation(null, 'telegram:123');
    $typed = ownedConversation('App\\SessionOwner', 'telegram:123');

    $ownership = app(ConversationOwnership::class);

    expect($ownership->owns($typeless, 'telegram:123'))->toBeTrue()
        ->and($ownership->owns($typed, 'telegram:123'))->toBeTrue()
        ->and($ownership->owns($typeless, 'telegram:123', 'App\\SessionOwner'))->toBeFalse();
});

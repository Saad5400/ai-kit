<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Saad\AiKit\Tests\ConversationsEncryptDisabledTestCase;
use Saad\AiKit\Tests\Support\LegacyConversationRows as Legacy;

uses(ConversationsEncryptDisabledTestCase::class, RefreshDatabase::class);

// An app that opted out of encryption runs the vendor store, which reads
// `steps` as plain JSON — so the backfill must write it plain too, while
// still opening any ciphertext left from a time the app did encrypt.
it('writes plaintext steps when the bound store does not encrypt', function () {
    Legacy::rewindSchema();

    $conversationId = Legacy::conversation();
    Legacy::row($conversationId, 'user', 'plain question', encrypt: false);
    Legacy::row($conversationId, 'assistant', 'plain answer', [
        'tool_calls' => [Legacy::call('call_1', 'LookupWidget')],
        'tool_results' => [Legacy::result('call_1', 'LookupWidget', 'plain result')],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ], encrypt: false);
    // Written while the app still encrypted.
    Legacy::row($conversationId, 'assistant', 'sealed answer', [
        'approval_state' => ['pending' => ['call_2' => null]],
        'tool_calls' => [Legacy::call('call_2', 'DeleteWidget')],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);

    Legacy::migrate();

    $rows = DB::table(Legacy::MESSAGES)->where('role', 'assistant')->orderBy('id')->get();

    expect(json_decode($rows[0]->steps, true)[0]['tool_calls'][0])->toMatchArray(['id' => 'call_1', 'result' => 'plain result'])
        ->and(json_decode($rows[1]->steps, true)[0])->toMatchArray(['content' => 'sealed answer'])
        ->and(json_decode($rows[1]->steps, true)[0]['tool_calls'][0])->toMatchArray(['id' => 'call_2', 'approval_reason' => null])
        ->and($rows[1]->status)->toBe(MessageStatus::Paused->value)
        ->and(json_decode($rows[1]->meta, true))->toMatchArray(['provider' => 'openrouter']);

    $pending = app(ConversationStore::class)->pendingApprovalsFor($conversationId);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->id)->toBe('call_2')
        ->and($pending[0]->reason)->toBeNull();
});

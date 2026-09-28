<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\StepsBackfill;
use Saad\AiKit\Tests\Support\DeleteWidgetTool;
use Saad\AiKit\Tests\Support\LegacyConversationRows as Legacy;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingApprovalAgent;

/**
 * The deploy window and the failure modes around the steps migration: rows
 * old (0.10) workers write or answer after it ran, ciphertext this app key
 * cannot open, and bounded memory on long threads.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    DeleteWidgetTool::$invocations = 0;

    Legacy::rewindSchema();
});

function rowOf(string $id): object
{
    return DB::table(Legacy::MESSAGES)->where('id', $id)->sole();
}

function foreignCiphertext(string $plaintext): string
{
    return (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString($plaintext);
}

function oldWorkerPause(string $conversationId): string
{
    Legacy::row($conversationId, 'user', 'please delete widget 4');

    return Legacy::row($conversationId, 'assistant', 'Deleting it now.', [
        'tool_calls' => [Legacy::call('call_del', 'DeleteWidget')],
        'approval_state' => ['pending' => ['call_del' => 'destructive']],
        'meta' => ['provider' => 'openrouter', 'model' => 'test/model', 'citations' => []],
    ]);
}

// M1 ------------------------------------------------------------------------

it('leaves a row it cannot decrypt untouched and names it, converting the rest', function () {
    Log::spy();

    $conversationId = Legacy::conversation();
    $readable = Legacy::row($conversationId, 'assistant', 'readable answer', ['meta' => ['provider' => 'openrouter']]);
    $lost = Legacy::row($conversationId, 'assistant', 'placeholder');

    $foreignMeta = foreignCiphertext('{"provider":"openrouter","model":"x"}');
    DB::table(Legacy::MESSAGES)->where('id', $lost)->update(['content' => foreignCiphertext('rotated away'), 'meta' => $foreignMeta]);
    $before = rowOf($lost);

    Legacy::migrate();

    $after = rowOf($lost);

    expect($after->steps)->toBeNull()
        ->and($after->meta)->toBe($foreignMeta)
        ->and($after->content)->toBe($before->content)
        ->and($after->status)->toBe(MessageStatus::Completed->value)
        ->and(ConversationContent::revealJson(rowOf($readable)->steps)[0]['content'])->toBe('readable answer');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, '1 conversation message(s) left untouched')
        && str_contains($message, $lost)
        && $context['ids'] === [$lost]);

    // The history replays without passing the ciphertext off as text.
    $history = Legacy::transcript(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 10));

    expect($history)->toBe([['assistant' => 'readable answer', 'calls' => []]]);

    $this->artisan('ai-kit:backfill-conversation-steps')
        ->expectsOutputToContain($lost)
        ->assertFailed();

    expect(rowOf($lost)->steps)->toBeNull();
});

it('leaves a whole conversation alone when any row\'s tool results do not decrypt', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    $call = Legacy::row($conversationId, 'assistant', '', ['tool_calls' => [Legacy::call('call_1', 'LookupWidget')]]);
    $answer = Legacy::row($conversationId, 'assistant', 'answer');
    DB::table(Legacy::MESSAGES)->where('id', $answer)->update(['tool_results' => foreignCiphertext('[{"id":"call_1","result":"x"}]')]);

    $report = StepsBackfill::configured()->run();

    expect($report->undecryptable)->toEqualCanonicalizing([$call, $answer])
        ->and(rowOf($call)->steps)->toBeNull()
        ->and(rowOf($answer)->steps)->toBeNull();
});

it('writes valid JSON steps even when decrypted text is not valid UTF-8', function () {
    $conversationId = Legacy::conversation();
    $id = Legacy::row($conversationId, 'assistant', "broken \xC3\x28 bytes");

    Legacy::migrate();

    $steps = ConversationContent::revealJson(rowOf($id)->steps);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['content'])->toStartWith('broken ');
});

// M2 ------------------------------------------------------------------------

it('heals an old worker\'s pause on first read so its card repaints and it resumes', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    $pausedId = oldWorkerPause($conversationId);

    expect(rowOf($pausedId)->steps)->toBeNull();

    $pending = app(ConversationStore::class)->pendingApprovalsFor($conversationId);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->id)->toBe('call_del')
        ->and($pending[0]->reason)->toBe('destructive')
        ->and((new StoredApprovals)->pending($conversationId)->pluck('id')->all())->toBe(['call_del'])
        ->and(rowOf($pausedId)->status)->toBe(MessageStatus::Paused->value)
        ->and(rowOf($pausedId)->steps)->not->toContain('DeleteWidget')
        ->and(ConversationContent::revealJson(rowOf($pausedId)->steps)[0]['tool_calls'][0])->toMatchArray(['id' => 'call_del', 'approval_reason' => 'destructive']);

    Http::fake(['*' => Http::response(OpenRouterSse::completion('Deleted widget 4.'))]);

    (new RememberingApprovalAgent)->continue($conversationId, (object) ['id' => (int) Legacy::OWNER_ID])
        ->prompt(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model');

    $row = rowOf($pausedId);

    expect(DeleteWidgetTool::$invocations)->toBe(1)
        ->and($row->status)->toBe(MessageStatus::Completed->value)
        ->and(DB::table(Legacy::MESSAGES)->where('conversation_id', $conversationId)->where('role', 'assistant')->count())->toBe(1);
});

it('heals a resume straight away, even with no read in between', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    $pausedId = oldWorkerPause($conversationId);

    Http::fake(['*' => Http::response(OpenRouterSse::completion('Deleted widget 4.'))]);

    (new RememberingApprovalAgent)->continue($conversationId, (object) ['id' => (int) Legacy::OWNER_ID])
        ->prompt(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model');

    expect(DeleteWidgetTool::$invocations)->toBe(1)
        ->and(rowOf($pausedId)->status)->toBe(MessageStatus::Completed->value);
});

it('heals an old worker\'s completed turn so it does not replay empty', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    Legacy::row($conversationId, 'user', 'late question');
    Legacy::row($conversationId, 'assistant', 'late answer', [
        'tool_calls' => [Legacy::call('call_1', 'LookupWidget')],
        'tool_results' => [Legacy::result('call_1', 'LookupWidget', 'late result')],
        'meta' => ['provider' => 'openrouter'],
    ]);

    expect(Legacy::transcript(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 10)))->toBe([
        ['user' => 'late question'],
        ['assistant' => '', 'calls' => ['call_1']],
        ['tool' => [['call_1', 'late result']]],
        ['assistant' => 'late answer', 'calls' => []],
    ])
        ->and(DB::table(Legacy::MESSAGES)->where('conversation_id', $conversationId)->whereNull('steps')->count())->toBe(0);
});

// L4 ------------------------------------------------------------------------

it('folds a result an old worker recorded after the conversion onto the converted pause', function (bool $viaCommand) {
    $conversationId = Legacy::conversation();
    $pausedId = oldWorkerPause($conversationId);

    Legacy::migrate();

    expect(rowOf($pausedId)->status)->toBe(MessageStatus::Paused->value);

    // An old worker resumes it the 0.10 way: the result merged into the
    // paused row's legacy columns, then its own resume row.
    DB::table(Legacy::MESSAGES)->where('id', $pausedId)->update([
        'tool_results' => ConversationContent::concealJson(json_encode([Legacy::result('call_del', 'DeleteWidget', 'deleted widget 4')])),
        'approval_state' => ConversationContent::concealJson('{"pending":[]}'),
    ]);
    Legacy::row($conversationId, 'assistant', 'Deleted widget 4.', ['meta' => ['provider' => 'openrouter']]);

    if ($viaCommand) {
        $this->artisan('ai-kit:backfill-conversation-steps')
            ->expectsOutputToContain('Folded late results into 1 paused conversation messages.')
            ->assertSuccessful();
    }

    expect(app(ConversationStore::class)->pendingApprovalsFor($conversationId))->toBe([])
        ->and(rowOf($pausedId)->status)->toBe(MessageStatus::Completed->value)
        ->and(ConversationContent::revealJson(rowOf($pausedId)->steps)[0]['tool_calls'][0])
        ->toMatchArray(['id' => 'call_del', 'result' => 'deleted widget 4', 'approval_reason' => 'destructive'])
        ->and(Legacy::transcript(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 10)))->toBe([
            ['user' => 'please delete widget 4'],
            ['assistant' => 'Deleting it now.', 'calls' => ['call_del']],
            ['tool' => [['call_del', 'deleted widget 4']]],
            ['assistant' => 'Deleted widget 4.', 'calls' => []],
        ]);
})->with(['self-heal' => false, 'command' => true]);

// M3 ------------------------------------------------------------------------

it('reads only the columns it needs, never select *', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    Legacy::row($conversationId, 'assistant', 'x', [
        'tool_calls' => [Legacy::call('call_1', 'LookupWidget')],
        'tool_results' => [Legacy::result('call_1', 'LookupWidget', 'r')],
    ]);

    $selects = [];
    DB::listen(function ($query) use (&$selects) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, Legacy::MESSAGES)) {
            $selects[] = $query->sql;
        }
    });

    StepsBackfill::configured()->run();

    expect($selects)->not->toBeEmpty()
        // EXISTS probes aside, every read names its columns.
        ->and(collect($selects)->reject(fn (string $sql) => str_starts_with($sql, 'select exists('))->filter(fn (string $sql) => str_contains($sql, 'select *')))->toBeEmpty()
        ->and(collect($selects)->contains(fn (string $sql) => str_contains($sql, 'select "id", "tool_results"')))->toBeTrue();
});

it('converts a long thread of large encrypted tool results in bounded memory', function () {
    Legacy::migrate();

    $conversationId = Legacy::conversation();
    $blob = str_repeat('widget data ', 10_000); // ~120 KB per result, sealed

    for ($i = 0; $i < 150; $i++) {
        Legacy::row($conversationId, 'assistant', "turn {$i}", [
            'tool_calls' => [Legacy::call("call_{$i}", 'LookupWidget')],
            'tool_results' => [Legacy::result("call_{$i}", 'LookupWidget', $blob)],
        ]);
    }

    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $report = StepsBackfill::configured(chunk: 10)->run();

    // Loading the thread at once holds ~25 MB of ciphertext alone.
    expect($report->written)->toBe(150)
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(12 * 1024 * 1024);
});

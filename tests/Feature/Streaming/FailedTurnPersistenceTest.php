<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Streaming\ErrorCode;
use Saad\AiKit\Streaming\InterruptedTurns;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Streaming\TurnCancelledException;
use Saad\AiKit\Streaming\TurnRunner;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingNoteAgent;
use Saad\AiKit\Tests\Support\SpyTurnBuffer;

/**
 * A turn that dies — or is stopped — is stored, end to end: a real
 * remembering agent, the kit's OpenRouter gateway, a faked HTTP provider,
 * the mapper / TurnRunner in front, and the conversation tables.
 *
 * The tables are the kit's 0.10 migration moved onto 1.0's `steps` /
 * `status` the way the store PR's phase-A migration does it (legacy trace
 * columns nullable, `participant_id` still the kit's string). Each case
 * runs through the stock DatabaseConversationStore and the kit's
 * EncryptedConversationStore as it stands on this branch; the store PR
 * (#19) carries the encrypted store's full 1.0 rewrite.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai-kit.gateway.retry.attempts', 1);
    config()->set('ai-kit.gateway.circuit_breaker.enabled', false);

    Schema::table('agent_conversation_messages', function (Blueprint $table) {
        $table->text('tool_calls')->nullable()->change();
        $table->text('tool_results')->nullable()->change();
        $table->longText('steps')->nullable();
        $table->string('status', 25)->default(MessageStatus::Completed->value);
    });

    RememberingNoteAgent::$saved = [];
    RememberingNoteAgent::$afterSave = null;

    Carbon::setTestNow(Carbon::now());

    // One fake for the whole test, fed from a queue.
    $GLOBALS['noteReplies'] = [];
    Http::fake(fn () => array_shift($GLOBALS['noteReplies']) ?? throw new OutOfBoundsException('No reply queued.'));
});

afterEach(function () {
    ToolProgress::unbind();
    Carbon::setTestNow();
});

dataset('stores', [
    'stock store' => [fn () => new DatabaseConversationStore],
    'encrypted store' => [fn () => new EncryptedConversationStore],
]);

function noteReplies(mixed ...$replies): void
{
    foreach ($replies as $reply) {
        $GLOBALS['noteReplies'][] = is_string($reply) ? Http::response($reply) : $reply;
    }
}

/** Step 0: the model calls SaveNote. */
function saveNoteStep(): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'Saving it. ']),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_save', 'function' => ['name' => 'SaveNote', 'arguments' => '{"text":"buy milk"}']]]], finishReason: 'tool_calls'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5]),
    ]);
}

/** A step that streams some text, then OpenRouter's mid-stream error frame. */
function erroringStep(string $partial = 'I saved your no'): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => $partial]),
        ['id' => 'gen-test-1', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
    ]);
}

function noteRows(): Collection
{
    return DB::table('agent_conversation_messages')->orderBy('id')->get();
}

function noteSteps(object $row): array
{
    return json_decode((string) ConversationContent::reveal($row->steps), true);
}

function noteMeta(object $row): array
{
    return json_decode((string) ConversationContent::reveal($row->meta), true);
}

function noteStream(): mixed
{
    return (new RememberingNoteAgent)->forUser((object) ['id' => 7])
        ->stream('remember to buy milk', provider: 'openrouter', model: 'test/model');
}

/**
 * The user row and ONE failed assistant row, carrying the error, the
 * completed SaveNote step and the interrupted step's partial text.
 */
function expectFailedTurnStored(string $error, ?string $partial): void
{
    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and($user->role)->toBe('user')
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->role)->toBe('assistant')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and(noteMeta($assistant)['error'])->toBe($error);

    $steps = noteSteps($assistant);

    expect($steps[0]['content'])->toBe('Saving it. ')
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call_save', 'name' => 'SaveNote', 'result' => 'saved']);

    if ($partial === null) {
        expect($steps)->toHaveCount(1);
    } else {
        expect($steps)->toHaveCount(2)
            ->and($steps[1]['content'])->toBe($partial)
            ->and($steps[1]['tool_calls'])->toBe([])
            ->and(ConversationContent::reveal($assistant->content))->toBe($partial);
    }
}

it('stores a turn whose provider errored inside the stream after a tool ran', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(saveNoteStep(), erroringStep());

    $events = [];
    $result = (new StreamEventMapper)->run(noteStream(), function (string $event, array $data) use (&$events) {
        $events[] = [$event, $data];
    });

    // The wire: the tool, the partial text, then EXACTLY ONE terminal error.
    expect($events)->toBe([
        ['delta', ['text' => 'Saving it. ']],
        ['tool', ['id' => 'call_save', 'name' => 'SaveNote', 'status' => 'running']],
        ['tool', ['id' => 'call_save', 'name' => 'SaveNote', 'status' => 'done', 'successful' => true]],
        ['delta', ['text' => 'I saved your no']],
        ['error', ['message' => 'Provider returned error', 'code' => ErrorCode::PROVIDER_UNAVAILABLE]],
    ])
        ->and($result->failed)->toBeTrue()
        ->and($result->text)->toBe('Saving it. I saved your no')
        ->and($result->toolResults)->toHaveCount(1)
        ->and(RememberingNoteAgent::$saved)->toBe(['buy milk']);

    expectFailedTurnStored('Provider returned error', 'I saved your no');
})->with('stores');

it('keeps the partial result on the TurnRunner outcome for an in-stream error', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(saveNoteStep(), erroringStep());

    $buffer = new SpyTurnBuffer;
    $buffer->start('t1');

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, $buffer);

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->cancelled)->toBeFalse()
        ->and($outcome->failure)->toBe('Provider returned error')
        ->and($outcome->failureCode)->toBe(ErrorCode::PROVIDER_UNAVAILABLE)
        ->and($outcome->exception)->toBeNull()
        ->and($outcome->result->text)->toBe('Saving it. I saved your no')
        ->and($outcome->result->toolCalls)->toHaveCount(1)
        ->and($outcome->result->toolResults)->toHaveCount(1);

    // The runner holds the terminal back for the app; nothing terminal is in the log.
    expect(collect($buffer->get('t1')['events'])->pluck('event')->all())->not->toContain('error')->not->toContain('done');

    expectFailedTurnStored('Provider returned error', 'I saved your no');
})->with('stores');

it('stores a turn whose next step threw an HTTP 502', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(saveNoteStep(), Http::response('upstream down', 502));

    $buffer = new SpyTurnBuffer;
    $buffer->start('t1');

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, $buffer);

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->exception)->not->toBeNull()
        ->and($outcome->failureCode)->toBe(ErrorCode::PROVIDER_UNAVAILABLE)
        // The partial result survives the throw.
        ->and($outcome->result->text)->toBe('Saving it. ')
        ->and($outcome->result->toolResults)->toHaveCount(1)
        ->and(RememberingNoteAgent::$saved)->toBe(['buy milk']);

    // The 502 never streamed, so there is no interrupted step to add.
    expectFailedTurnStored($outcome->exception->getMessage(), null);
})->with('stores');

it('keeps the user row when the very first step errors in the stream', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(erroringStep('Let me th'));

    $events = [];
    (new StreamEventMapper)->run(noteStream(), function (string $event, array $data) use (&$events) {
        $events[] = $event;
    });

    expect($events)->toBe(['delta', 'error']);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and(ConversationContent::reveal($assistant->content))->toBe('Let me th')
        ->and(noteSteps($assistant))->toHaveCount(1)
        ->and(noteMeta($assistant)['error'])->toBe('Provider returned error');
})->with('stores');

it('keeps the user row when the very first request throws', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(Http::response('upstream down', 502));

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, tap(new SpyTurnBuffer)->start('t1'));

    expect($outcome->failed)->toBeTrue()
        ->and($outcome->failureCode)->toBe(ErrorCode::PROVIDER_UNAVAILABLE);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and((string) ConversationContent::reveal($assistant->content))->toBe('')
        ->and(noteMeta($assistant)['error'])->toBe($outcome->exception->getMessage());
})->with('stores');

it('stores a stopped turn with the tool it already ran, and still reports it cancelled', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    // Only step 0 is queued: a stop that failed to land would request step 1
    // and hit the empty queue.
    noteReplies(saveNoteStep());

    $buffer = new SpyTurnBuffer;
    $buffer->start('t1');

    // The user presses stop while the tool runs; the poll after the next
    // event sees it.
    RememberingNoteAgent::$afterSave = function () use ($buffer) {
        $buffer->cancel('t1');
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
    };

    $mapper = (new StreamEventMapper)->doneUsing(fn ($result) => ['text' => $result->text]);

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), $mapper, $buffer);

    expect($outcome->cancelled)->toBeTrue()
        ->and($outcome->failed)->toBeFalse()
        ->and($outcome->done)->toBe(['text' => 'Saving it. '])
        ->and(RememberingNoteAgent::$saved)->toBe(['buy milk']);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and(TurnCancelledException::marks(noteMeta($assistant)))->toBeTrue()
        ->and(noteSteps($assistant))->toHaveCount(1)
        ->and(noteSteps($assistant)[0]['tool_calls'][0])->toMatchArray(['id' => 'call_save', 'result' => 'saved']);
})->with('stores');

it('keeps the user row of a turn stopped before it produced anything', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(saveNoteStep());

    $buffer = new SpyTurnBuffer;
    $buffer->start('t1');
    $buffer->cancel('t1');

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, $buffer);

    expect($outcome->cancelled)->toBeTrue()
        ->and(RememberingNoteAgent::$saved)->toBe([]);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and(TurnCancelledException::marks(noteMeta($assistant)))->toBeTrue()
        ->and((string) ConversationContent::reveal($assistant->content))->toBe('');
})->with('stores');

it('stores a completed turn exactly as stock does', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(saveNoteStep(), OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'Saved.'], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 2]),
    ]));

    $stream = noteStream();
    $result = (new StreamEventMapper)->run($stream, fn () => null);

    expect($result->failed)->toBeFalse()
        ->and(app(InterruptedTurns::class)->tracking($stream->invocationId))->toBeFalse();

    [, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and($assistant->status)->toBe(MessageStatus::Completed->value)
        ->and(noteSteps($assistant))->toHaveCount(2);
})->with('stores');

it('leaves stock behaviour alone when remembering interrupted turns is off', function () {
    app()->instance(ConversationStore::class, new DatabaseConversationStore);
    app()->instance(InterruptedTurns::class, new InterruptedTurns(enabled: false));

    noteReplies(erroringStep());

    (new StreamEventMapper)->run(noteStream(), fn () => null);

    // Stock stores nothing for a run that died in its first step.
    expect(noteRows())->toHaveCount(0);
});

it('keeps the user row when a prompted (non-streamed) turn dies in its first step', function (Closure $store) {
    app()->instance(ConversationStore::class, $store());

    noteReplies(Http::response('upstream down', 502));

    expect(fn () => (new RememberingNoteAgent)->forUser((object) ['id' => 7])
        ->prompt('remember to buy milk', provider: 'openrouter', model: 'test/model'))
        ->toThrow(ProviderOverloadedException::class);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value);
})->with('stores');

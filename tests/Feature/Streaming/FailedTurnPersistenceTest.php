<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\ConversationContent;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Conversations\StoredSteps;
use Saad\AiKit\Conversations\TurnState;
use Saad\AiKit\Streaming\ErrorCode;
use Saad\AiKit\Streaming\InterruptedTurns;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Streaming\TurnCancelledException;
use Saad\AiKit\Streaming\TurnRunner;
use Saad\AiKit\Tests\Support\DeleteWidgetTool;
use Saad\AiKit\Tests\Support\LegacyConversationRows as Legacy;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingApprovalAgent;
use Saad\AiKit\Tests\Support\RememberingNoteAgent;
use Saad\AiKit\Tests\Support\SpyTurnBuffer;

/**
 * A turn that dies — or is stopped — is stored, end to end: a real
 * remembering agent, the kit's OpenRouter gateway, a faked HTTP provider,
 * the mapper / TurnRunner in front, and the conversation tables.
 *
 * The tables are the kit's own migrations (0.10 create + the phase-A move
 * onto 1.0's `steps` / `status`: legacy trace columns still present and
 * nullable, `participant_id` still the kit's string), so the encrypted
 * store's self-heal probe runs on every read, as it does mid-deploy. Each
 * case runs through the stock DatabaseConversationStore and the kit's
 * EncryptedConversationStore; under the latter every failed or stopped row
 * must be sealed at rest (content / steps / meta ciphertext, meta.error
 * included).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai-kit.gateway.retry.attempts', 1);
    config()->set('ai-kit.gateway.circuit_breaker.enabled', false);

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

/**
 * Under the encrypted store the row is sealed at rest: content (when not
 * blank), steps and meta are ciphertext, and none of $secrets — the error
 * included — appears in any raw column. Under the stock store it is the
 * plaintext the vendor writes.
 */
function expectNoteRowSealed(object $row, array $secrets): void
{
    if (! app(ConversationStore::class) instanceof EncryptedConversationStore) {
        expect(json_decode((string) $row->meta, true))->toBeArray();

        return;
    }

    foreach (['steps', 'meta'] as $column) {
        if (! in_array($row->{$column}, [null, '[]'], true)) {
            expect(ConversationContent::looksEncrypted($row->{$column}))->toBeTrue("{$column} is not sealed");
        }
    }

    if ((string) $row->content !== '') {
        expect(ConversationContent::looksEncrypted($row->content))->toBeTrue('content is not sealed');
    }

    foreach (['content', 'steps', 'meta', 'attachments', 'usage'] as $column) {
        foreach ($secrets as $secret) {
            expect((string) $row->{$column})->not->toContain($secret);
        }
    }
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
        ->and(noteMeta($assistant)['error'])->toBe($error)
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Failed);

    expectNoteRowSealed($user, ['buy milk']);
    expectNoteRowSealed($assistant, array_filter(['buy milk', 'SaveNote', 'call_save', 'Saving it', $error, $partial]));

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

    expectNoteRowSealed($user, ['buy milk']);
    expectNoteRowSealed($assistant, ['Let me th', 'Provider returned error']);
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

    expectNoteRowSealed($user, ['buy milk']);
    expectNoteRowSealed($assistant, [$outcome->exception->getMessage()]);
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
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Stopped)
        ->and(noteSteps($assistant))->toHaveCount(1)
        ->and(noteSteps($assistant)[0]['tool_calls'][0])->toMatchArray(['id' => 'call_save', 'result' => 'saved']);

    expectNoteRowSealed($assistant, ['buy milk', 'SaveNote', 'Saving it', TurnCancelledException::MESSAGE]);
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
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Stopped)
        ->and((string) ConversationContent::reveal($assistant->content))->toBe('');

    expectNoteRowSealed($user, ['buy milk']);
    expectNoteRowSealed($assistant, [TurnCancelledException::MESSAGE]);
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
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Failed);

    expectNoteRowSealed($user, ['buy milk']);
    expectNoteRowSealed($assistant, [(string) noteMeta($assistant)['error']]);
})->with('stores');

// Traces off ------------------------------------------------------------------

function tracesOffStore(): void
{
    config()->set('ai-kit.conversations.persist_tool_traces', false);
    app()->instance(ConversationStore::class, new EncryptedConversationStore);
}

it('stores a failed turn content-only with traces off, keeping only its sealed error', function () {
    tracesOffStore();

    noteReplies(saveNoteStep(), erroringStep());

    (new StreamEventMapper)->run(noteStream(), fn () => null);

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        // The text the user saw, as ONE step: no tool call, result or reasoning.
        ->and(noteSteps($assistant))->toBe([StoredSteps::step("Saving it. \n\nI saved your no")])
        ->and(noteMeta($assistant))->toBe(['error' => 'Provider returned error'])
        ->and($assistant->usage)->toBe('[]')
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Failed)
        ->and(RememberingNoteAgent::$saved)->toBe(['buy milk']);

    expectNoteRowSealed($assistant, ['SaveNote', 'call_save', 'buy milk', 'Provider returned error', 'I saved']);

    // History replays the failed turn as the assistant text alone.
    $history = app(ConversationStore::class)->getLatestConversationMessages($assistant->conversation_id, 10);

    expect(Legacy::transcript($history))->toBe([
        ['user' => 'remember to buy milk'],
        ['assistant' => "Saving it. \n\nI saved your no", 'calls' => []],
    ]);
});

it('keeps a stopped turn recognisable with traces off', function () {
    tracesOffStore();

    noteReplies(saveNoteStep());

    $buffer = tap(new SpyTurnBuffer)->start('t1');

    RememberingNoteAgent::$afterSave = function () use ($buffer) {
        $buffer->cancel('t1');
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
    };

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, $buffer);

    [, $assistant] = [...noteRows()];

    expect($outcome->cancelled)->toBeTrue()
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and(noteSteps($assistant))->toBe([StoredSteps::step('Saving it. ')])
        ->and(noteMeta($assistant))->toBe(['error' => TurnCancelledException::MESSAGE])
        ->and(TurnState::of($assistant->status, $assistant->meta))->toBe(TurnState::Stopped);

    expectNoteRowSealed($assistant, ['SaveNote', TurnCancelledException::MESSAGE]);
});

it('keeps the user row and an empty failed row with traces off when the first request throws', function () {
    tracesOffStore();

    noteReplies(Http::response('upstream down', 502));

    $outcome = app(TurnRunner::class)->run('t1', fn () => noteStream(), new StreamEventMapper, tap(new SpyTurnBuffer)->start('t1'));

    [$user, $assistant] = [...noteRows()];

    expect(noteRows())->toHaveCount(2)
        ->and(ConversationContent::reveal($user->content))->toBe('remember to buy milk')
        ->and($assistant->status)->toBe(MessageStatus::Failed->value)
        ->and($assistant->steps)->toBe('[]')
        ->and((string) $assistant->content)->toBe('')
        ->and(noteMeta($assistant))->toBe(['error' => $outcome->exception->getMessage()]);

    expectNoteRowSealed($assistant, [$outcome->exception->getMessage()]);

    // The blank failed turn replays as nothing.
    expect(Legacy::transcript(app(ConversationStore::class)->getLatestConversationMessages($assistant->conversation_id, 10)))
        ->toBe([['user' => 'remember to buy milk']]);
});

// Resume, and the self-heal ---------------------------------------------------

function widgetPauseStep(): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'On it. ']),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_del', 'type' => 'function', 'function' => ['name' => 'DeleteWidget', 'arguments' => '{"id":4}']]]], finishReason: 'tool_calls'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5]),
    ]);
}

function drainTurn(iterable $stream): void
{
    foreach ($stream as $event) {
        //
    }
}

it('folds a resume that dies mid-stream into the paused row, failed and sealed', function () {
    DeleteWidgetTool::$invocations = 0;

    noteReplies(widgetPauseStep());

    $paused = (new RememberingApprovalAgent)->forUser((object) ['id' => 7])
        ->stream('please delete widget 4', provider: 'openrouter', model: 'test/model');
    drainTurn($paused);

    $pausedRow = noteRows()->where('role', 'assistant')->sole();

    expect($pausedRow->status)->toBe(MessageStatus::Paused->value);

    noteReplies(erroringStep('Deleted wid'));

    $events = [];
    $result = (new StreamEventMapper)->run(
        (new RememberingApprovalAgent)->continue($paused->conversationId, (object) ['id' => 7])
            ->stream(Decisions::from(['call_del' => true]), provider: 'openrouter', model: 'test/model'),
        function (string $event) use (&$events) {
            $events[] = $event;
        },
    );

    $row = noteRows()->where('role', 'assistant')->sole();
    $steps = noteSteps($row);

    expect($result->failed)->toBeTrue()
        ->and(end($events))->toBe('error')
        ->and(DeleteWidgetTool::$invocations)->toBe(1)
        ->and($row->id)->toBe($pausedRow->id)
        ->and($row->status)->toBe(MessageStatus::Failed->value)
        ->and(TurnState::of($row->status, $row->meta))->toBe(TurnState::Failed)
        ->and(noteMeta($row)['error'])->toBe('Provider returned error')
        ->and($steps[0]['tool_calls'][0])->toMatchArray(['id' => 'call_del', 'result' => 'deleted widget 4'])
        // The interrupted step's partial text rides along.
        ->and(end($steps)['content'])->toBe('Deleted wid')
        ->and(app(ConversationStore::class)->pendingApprovalsFor($paused->conversationId))->toBe([]);

    expectNoteRowSealed($row, ['DeleteWidget', 'deleted widget', 'Deleted wid', 'On it', 'Provider returned error']);
});

it('heals an old worker\'s rows before a turn that fails on top of them, sealing both', function () {
    $conversationId = Legacy::conversation();
    Legacy::row($conversationId, 'user', 'hello');
    $old = Legacy::row($conversationId, 'assistant', 'Hi! I keep notes.', ['meta' => ['provider' => 'openrouter', 'model' => 'test/model']]);

    expect(DB::table('agent_conversation_messages')->where('id', $old)->value('steps'))->toBeNull();

    noteReplies(erroringStep('Let me th'));

    (new StreamEventMapper)->run(
        (new RememberingNoteAgent)->continue($conversationId, (object) ['id' => 7])
            ->stream('remember to buy milk', provider: 'openrouter', model: 'test/model'),
        fn () => null,
    );

    $rows = noteRows();
    $healed = $rows->firstWhere('id', $old);
    $failed = $rows->last();

    expect($rows)->toHaveCount(4)
        ->and(noteSteps($healed))->toBe([StoredSteps::step('Hi! I keep notes.')])
        ->and($failed->status)->toBe(MessageStatus::Failed->value)
        ->and(ConversationContent::reveal($failed->content))->toBe('Let me th')
        ->and(noteMeta($failed)['error'])->toBe('Provider returned error')
        // The model saw the healed history.
        ->and(json_encode(Http::recorded()[0][0]->data()['messages']))->toContain('Hi! I keep notes.');

    expectNoteRowSealed($healed, ['I keep notes']);
    expectNoteRowSealed($failed, ['Let me th', 'Provider returned error']);
});

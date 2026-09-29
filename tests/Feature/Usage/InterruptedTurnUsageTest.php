<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Saad\AiKit\Gateway\GenerationCostResolver;
use Saad\AiKit\Gateway\GenerationCostUnavailable;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Streaming\Events\TurnStopped;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Streaming\TurnCancelledException;
use Saad\AiKit\Streaming\TurnOutcome;
use Saad\AiKit\Streaming\TurnRunner;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingNoteAgent;
use Saad\AiKit\Tests\Support\SpyTurnBuffer;
use Saad\AiKit\Usage\Events\InterruptedSpendResolved;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Saad\AiKit\Usage\InterruptedSpend;
use Saad\AiKit\Usage\ResolveInterruptedSpend;
use Saad\AiKit\Usage\UsageEvent;

/**
 * Interrupted turns, end to end: a real remembering agent streaming through
 * the kit's gateway over faked HTTP, TurnRunner in front, the usage layer's
 * `stopped` / `failed` row, the budget, and the late pricing of the step
 * the interruption cut off from OpenRouter's (faked) `/generation`.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai-kit.gateway.retry.attempts', 1);
    config()->set('ai-kit.gateway.circuit_breaker.enabled', false);
    config()->set('ai-kit.safety.daily_usd_limit', 100.0);

    RememberingNoteAgent::$saved = [];
    RememberingNoteAgent::$afterSave = null;

    Carbon::setTestNow(Carbon::now());
    Sleep::fake();

    // A real (delaying) connection; the fake intercepts the pushes.
    config()->set('queue.connections.async', ['driver' => 'database']);
    config()->set('queue.default', 'async');
    Queue::fake();

    // Chat completions and generation lookups each from their own queue.
    $GLOBALS['icChat'] = [];
    $GLOBALS['icGeneration'] = [];
    $GLOBALS['icLookups'] = [];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/generation')) {
            $GLOBALS['icLookups'][] = $request->data()['id'] ?? null;

            return array_shift($GLOBALS['icGeneration']) ?? Http::response(['error' => ['code' => 404, 'message' => 'Generation not found']], 404);
        }

        return array_shift($GLOBALS['icChat']) ?? throw new OutOfBoundsException('No chat reply queued.');
    });

    $GLOBALS['icRecorded'] = [];
    $GLOBALS['icResolved'] = [];

    Event::listen(TurnUsageRecorded::class, function (TurnUsageRecorded $event) {
        $GLOBALS['icRecorded'][] = $event;
    });

    Event::listen(InterruptedSpendResolved::class, function (InterruptedSpendResolved $event) {
        $GLOBALS['icResolved'][] = $event;
    });
});

afterEach(function () {
    ToolProgress::unbind();
    Carbon::setTestNow();
});

function icChat(string ...$bodies): void
{
    foreach ($bodies as $body) {
        $GLOBALS['icChat'][] = Http::response($body);
    }
}

function icNotYet(int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        $GLOBALS['icGeneration'][] = Http::response(['error' => ['code' => 404, 'message' => 'Generation not found']], 404);
    }
}

function icPriced(string $id, float $cost): void
{
    $GLOBALS['icGeneration'][] = Http::response(['data' => ['id' => $id, 'total_cost' => $cost, 'cancelled' => true]]);
}

/** A single-step answer whose final usage frame carries the cost. */
function icAnswerStep(string $id = 'gen-answer', float $cost = 0.0123): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'The answer is '], id: $id),
        OpenRouterSse::chunk(['content' => 'forty-two.'], id: $id),
        OpenRouterSse::chunk([], finishReason: 'stop', id: $id),
        OpenRouterSse::usageFrame(['prompt_tokens' => 900, 'completion_tokens' => 400, 'cost' => $cost], id: $id),
    ]);
}

/** Step 0 of a tool turn: SaveNote, priced. */
function icSaveStep(): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'Saving it. '], id: 'gen-save'),
        OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_save', 'function' => ['name' => 'SaveNote', 'arguments' => '{"text":"buy milk"}']]]], finishReason: 'tool_calls', id: 'gen-save'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001], id: 'gen-save'),
    ]);
}

function icStream(): mixed
{
    return (new RememberingNoteAgent)->forUser((object) ['id' => 7])
        ->stream('what is the answer?', provider: 'openrouter', model: 'test/model');
}

function icRun(string $turnId, bool $stopAtOnce = false, array $meta = []): TurnOutcome
{
    $buffer = new SpyTurnBuffer;
    $buffer->start($turnId);

    if ($stopAtOnce) {
        $buffer->cancel($turnId);
    }

    return app(TurnRunner::class)->run($turnId, fn () => icStream(), new StreamEventMapper, $buffer, meta: $meta);
}

/** Run every queued ResolveInterruptedSpend in order, the way a worker would. */
function icWork(): int
{
    $ran = 0;

    while (($job = Queue::pushed(ResolveInterruptedSpend::class)->get($ran)) !== null) {
        app()->call([$job, 'handle']);
        $ran++;
    }

    return $ran;
}

it('records a stopped single-step answer and prices its cut-off step on the second poll', function () {
    icChat(icAnswerStep());

    $outcome = icRun('t1', stopAtOnce: true, meta: ['user_id' => 7]);

    expect($outcome->cancelled)->toBeTrue();

    // ONE `stopped` row: nothing completed, so no cost yet; the cut-off
    // generation waits for its price.
    $row = UsageEvent::sole();

    expect($row->status)->toBe('stopped')
        ->and($row->cost_usd)->toBeNull()
        ->and($row->streamed)->toBeTrue()
        ->and($row->context)->toMatchArray(['turn_id' => 't1', 'pending_generation_ids' => ['gen-answer']])
        ->and($GLOBALS['icRecorded'])->toHaveCount(1)
        ->and($GLOBALS['icRecorded'][0]->stopped())->toBeTrue()
        ->and($GLOBALS['icRecorded'][0]->interrupted())->toBeTrue()
        ->and(app(SpendCollector::class)->pendingGenerationIds())->toBe([]);

    Queue::assertPushed(ResolveInterruptedSpend::class, fn ($job) => $job->delay === 5 && $job->pendingGenerationIds === ['gen-answer']);

    icNotYet();
    icPriced('gen-answer', 0.0123);

    expect(icWork())->toBe(2)
        ->and($GLOBALS['icLookups'])->toBe(['gen-answer', 'gen-answer'])
        ->and(Queue::pushed(ResolveInterruptedSpend::class)->get(1)->delay)->toBe(20);

    expect($GLOBALS['icResolved'])->toHaveCount(1);

    /** @var InterruptedSpendResolved $resolved */
    $resolved = $GLOBALS['icResolved'][0];

    expect($resolved->costUsd)->toEqualWithDelta(0.0123, 1e-9)
        ->and($resolved->generationIds)->toBe(['gen-answer'])
        ->and($resolved->turnId)->toBe('t1')
        ->and($resolved->meta)->toBe(['user_id' => 7])
        ->and($resolved->stopped())->toBeTrue()
        ->and($resolved->billable())->toBeTrue()
        ->and($resolved->debitKey())->toBe('debit:turn:t1:interrupted:'.$row->invocation_id)
        ->and($resolved->turn->is($row))->toBeTrue()
        ->and($resolved->usage->status)->toBe('resolved')
        ->and($resolved->usage->invocation_id)->toBe($row->invocation_id)
        ->and($resolved->usage->cost_usd)->toEqualWithDelta(0.0123, 1e-9)
        ->and($resolved->usage->cost_source)->toBe('generation_lookup')
        ->and($resolved->usage->context)->toMatchArray(['resolves' => $row->id, 'turn_status' => 'stopped', 'turn_id' => 't1'])
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.0123, 1e-6);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://openrouter.ai/api/v1/generation?id=gen-answer')
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

it('records a failed turn with its completed steps, counts it on the budget, and reports the cut-off step as not billable', function () {
    icChat(icSaveStep(), OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'I saved your no'], id: 'gen-died'),
        ['id' => 'gen-died', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
    ]));

    expect(icRun('t2')->failed)->toBeTrue();

    $row = UsageEvent::sole();

    expect($row->status)->toBe('failed')
        ->and($row->cost_usd)->toEqualWithDelta(0.001, 1e-9)
        ->and($row->cost_source)->toBe('provider')
        ->and($row->generation_ids)->toBe(['gen-save'])
        ->and($row->prompt_tokens)->toBe(10)
        ->and($row->completion_tokens)->toBe(5)
        ->and($row->error)->toContain('Provider returned error')
        ->and($GLOBALS['icRecorded'][0]->failed())->toBeTrue()
        // The budget listener counted the completed step through the event.
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.001, 1e-6);

    icPriced('gen-died', 0.002);
    icWork();

    expect($GLOBALS['icResolved'])->toHaveCount(1)
        ->and($GLOBALS['icResolved'][0]->costUsd)->toEqualWithDelta(0.002, 1e-9)
        ->and($GLOBALS['icResolved'][0]->failed())->toBeTrue()
        ->and($GLOBALS['icResolved'][0]->billable())->toBeFalse()
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.003, 1e-6);
});

it('writes no second row when a stop loses the race to completion', function () {
    icChat(icAnswerStep());

    Event::listen(AgentStreamed::class, function (AgentStreamed $event) {
        $GLOBALS['icStreamed'] = $event;
    });

    expect(icRun('t3')->cancelled)->toBeFalse();

    $completed = $GLOBALS['icStreamed'];

    // A late AgentFailed for the same run (a stop that landed after the end).
    event(new AgentFailed($completed->invocationId, $completed->prompt, new TurnCancelledException));

    expect(UsageEvent::query()->pluck('status')->all())->toBe(['ok'])
        ->and(UsageEvent::sole()->cost_usd)->toEqualWithDelta(0.0123, 1e-9)
        ->and($GLOBALS['icRecorded'])->toHaveCount(1)
        ->and($GLOBALS['icRecorded'][0]->interrupted())->toBeFalse();

    Queue::assertNothingPushed();
});

it('gives up after the configured attempts without throwing', function () {
    Log::spy();

    icChat(icAnswerStep());
    icRun('t4', stopAtOnce: true);

    expect(icWork())->toBe(5)
        ->and(Queue::pushed(ResolveInterruptedSpend::class)->pluck('delay')->all())->toBe([5, 20, 60, 180, 300])
        ->and($GLOBALS['icLookups'])->toHaveCount(5)
        ->and($GLOBALS['icResolved'])->toBe([])
        ->and(UsageEvent::query()->where('status', 'resolved')->count())->toBe(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['generation_ids'] === ['gen-answer'] && $context['attempts'] === 5);
});

it('never double-counts or reports twice when a job is redelivered', function () {
    icChat(icAnswerStep());
    icRun('t5', stopAtOnce: true);

    $job = Queue::pushed(ResolveInterruptedSpend::class)->first();

    icPriced('gen-answer', 0.05);
    icPriced('gen-answer', 0.05);

    app()->call([$job, 'handle']);
    app()->call([clone $job, 'handle']);

    expect($GLOBALS['icResolved'])->toHaveCount(1)
        ->and(UsageEvent::query()->where('status', 'resolved')->count())->toBe(1)
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.05, 1e-6);
});

it('fires the event again after a listener failure, reusing the one delta row', function () {
    icChat(icAnswerStep());
    icRun('t6', stopAtOnce: true);

    $GLOBALS['icThrowOnce'] = true;

    Event::listen(InterruptedSpendResolved::class, function () {
        if ($GLOBALS['icThrowOnce']) {
            $GLOBALS['icThrowOnce'] = false;

            throw new RuntimeException('ledger down');
        }
    });

    $job = Queue::pushed(ResolveInterruptedSpend::class)->first();

    icPriced('gen-answer', 0.02);
    icPriced('gen-answer', 0.02);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'ledger down');

    // The queue's retry of the same payload.
    app()->call([$job, 'handle']);

    expect(UsageEvent::query()->where('status', 'resolved')->count())->toBe(1)
        ->and(collect($GLOBALS['icResolved'])->pluck('usage.id')->unique()->count())->toBe(1)
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.02, 1e-6);
});

it('prices a generation cut off inside a turn that still completed, as billable', function () {
    icChat(icSaveStep(), icAnswerStep('gen-final', 0.003));

    // A sub-agent / failover attempt cut off mid-turn left a pending id.
    RememberingNoteAgent::$afterSave = fn () => app(SpendCollector::class)->recordPendingGeneration('gen-cut');

    expect(icRun('t7')->failed)->toBeFalse();

    $row = UsageEvent::sole();

    expect($row->status)->toBe('ok')
        ->and($row->cost_usd)->toEqualWithDelta(0.004, 1e-9);

    icPriced('gen-cut', 0.0007);
    icWork();

    expect($GLOBALS['icResolved'])->toHaveCount(1)
        ->and($GLOBALS['icResolved'][0]->billable())->toBeTrue()
        ->and($GLOBALS['icResolved'][0]->stopped())->toBeFalse()
        ->and($GLOBALS['icResolved'][0]->turn->status)->toBe('ok');
});

it('starts every TurnRunner turn with a clean collector', function () {
    // What an earlier turn on this worker (or a sync-queued caller) left.
    app(SpendCollector::class)->recordCost(9.99, streamed: true);
    app(SpendCollector::class)->recordPendingGeneration('gen-stale');

    icChat(icAnswerStep());
    icRun('t8');

    expect(UsageEvent::sole()->cost_usd)->toEqualWithDelta(0.0123, 1e-9);

    Queue::assertNothingPushed();
});

it('records a prompted (non-streamed) turn that failed', function () {
    $GLOBALS['icChat'][] = Http::response('upstream down', 502);

    try {
        (new RememberingNoteAgent)->forUser((object) ['id' => 7])->prompt('hi', provider: 'openrouter', model: 'test/model');

        $this->fail('The prompt should have thrown.');
    } catch (ProviderOverloadedException) {
        // The 502, after the client's retries.
    }

    $row = UsageEvent::sole();

    expect($row->status)->toBe('failed')
        ->and($row->streamed)->toBeFalse()
        ->and($row->cost_usd)->toBeNull()
        ->and($GLOBALS['icRecorded'][0]->failed())->toBeTrue();

    Queue::assertNothingPushed();
});

it('skips the pricing when disabled but still records the row', function () {
    config()->set('ai-kit.spend.resolve_interrupted', false);

    icChat(icAnswerStep());
    icRun('t9', stopAtOnce: true);

    expect(UsageEvent::sole()->status)->toBe('stopped');

    Queue::assertNothingPushed();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/generation'));
});

it('prices inline when a sync window is configured', function () {
    config()->set('ai-kit.spend.sync_window_seconds', 6);

    icChat(icAnswerStep());
    icNotYet();
    icPriced('gen-answer', 0.0123);

    icRun('t10', stopAtOnce: true, meta: ['user_id' => 7]);

    expect($GLOBALS['icResolved'])->toHaveCount(1)
        ->and($GLOBALS['icResolved'][0]->costUsd)->toEqualWithDelta(0.0123, 1e-9);

    Queue::assertNothingPushed();
});

it('never reports an id as pending once its step completed', function () {
    $spend = app(SpendCollector::class);

    $spend->recordPendingGeneration('gen-a');
    $spend->recordPendingGeneration('gen-a');
    $spend->recordPendingGeneration('gen-b');
    $spend->recordGenerationId('gen-a', streamed: true);

    expect($spend->pendingGenerationIds())->toBe(['gen-b']);

    $spend->flush();

    expect($spend->pendingGenerationIds())->toBe([]);
});

it('resolves inside a bounded window and reads a zero cost as an answer', function () {
    $resolver = new GenerationCostResolver(app(HttpFactory::class), 'k', windowSeconds: 2.0, backoffMs: [500, 1000, 1500]);

    icNotYet(5);

    // 0.5 + 1.0 fit the 2s window, 1.5 more does not: three looks, then null.
    expect($resolver->resolve('gen-slow'))->toBeNull()
        ->and($GLOBALS['icLookups'])->toHaveCount(3);

    $GLOBALS['icGeneration'] = [];
    icPriced('gen-free', 0.0);

    expect($resolver->fetch('gen-free'))->toBe(0.0)
        ->and(fn () => (new GenerationCostResolver(app(HttpFactory::class), null))->fetch('gen-free'))
        ->toThrow(GenerationCostUnavailable::class, 'no API key');
});

// --- Review hardening -------------------------------------------------------

it('never breaks the turn when the queue refuses the job: the row stays and the unpriced ids are logged', function () {
    Log::spy();

    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    icChat(icAnswerStep());

    expect(icRun('r1', stopAtOnce: true)->cancelled)->toBeTrue()
        ->and(UsageEvent::sole()->status)->toBe('stopped');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ($context['generation_ids'] ?? null) === ['gen-answer']
        && ($context['reason'] ?? null) === 'the pricing job could not be queued');
});

it('never runs the inline window for a failed turn', function () {
    config()->set('ai-kit.spend.sync_window_seconds', 6);

    icChat(OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'Let me th'], id: 'gen-died'),
        ['id' => 'gen-died', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
    ]));

    expect(icRun('r2')->failed)->toBeTrue()
        ->and($GLOBALS['icLookups'])->toBe([]);

    Queue::assertPushed(ResolveInterruptedSpend::class, 1);
});

it('logs a configuration that can never price once, and queues nothing', function (Closure $misconfigure, string $reason) {
    Log::spy();
    $misconfigure();

    icChat(icAnswerStep());
    icRun('r3', stopAtOnce: true);

    Queue::assertNothingPushed();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/generation'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, $reason));
})->with([
    'no key' => [fn () => config()->set('ai.providers.openrouter.key', null), 'no API key'],
    'not an OpenRouter provider' => [function () {
        config()->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'sk-ant']);
        config()->set('ai-kit.spend.provider', 'anthropic');
    }, 'is not an OpenRouter provider'],
]);

it('stops at once on a refused key, naming the status', function () {
    Log::spy();

    icChat(icAnswerStep());
    icRun('r4', stopAtOnce: true);

    $GLOBALS['icGeneration'][] = Http::response(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401);

    expect(icWork())->toBe(1);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, 'HTTP 401'));
});

it('names the last HTTP status when it gives up', function () {
    Log::spy();

    icChat(icAnswerStep());
    icRun('r5', stopAtOnce: true);
    icWork();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['last_status'] === 404);
});

it('holds a settlement another worker is running and retries it, then fires once the lock is gone', function () {
    icChat(icAnswerStep());
    icRun('r6', stopAtOnce: true);

    icPriced('gen-answer', 0.03);
    icPriced('gen-answer', 0.03);

    $job = Queue::pushed(ResolveInterruptedSpend::class)->first();
    $key = 'ai-kit:interrupted-spend:'.$job->turnUsageId.':'.InterruptedSpend::fingerprint(['gen-answer']);

    // A worker killed mid-settlement (a deploy) left its short lock behind.
    cache()->add($key.':lock', 1, 120);

    $held = (clone $job)->withFakeQueueInteractions();
    app()->call([$held, 'handle']);

    $held->assertReleased(60);
    expect($GLOBALS['icResolved'])->toBe([]);

    cache()->forget($key.':lock');

    app()->call([$job, 'handle']);

    expect($GLOBALS['icResolved'])->toHaveCount(1)
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.03, 1e-6);
});

it('keeps a turn\'s spend, pending ids, turn id and meta out of a job hydrated by another Context', function () {
    TurnContext::beginTurn('r7', ['user_id' => 7]);

    $spend = app(SpendCollector::class);
    $spend->recordCost(0.5, streamed: true);
    $spend->recordGenerationId('gen-1', streamed: true);
    $spend->recordPendingGeneration('gen-2');

    $payload = Context::dehydrate();

    // A worker: a fresh Context hydrating the payload.
    $worker = new ContextRepository(app('events'));
    $worker->hydrate($payload);

    expect($worker->get('ai.openrouter_costs'))->toBeNull()
        ->and($worker->get('ai.openrouter_generation_ids'))->toBeNull()
        ->and($worker->get('ai.openrouter_pending_generation_ids'))->toBeNull()
        ->and($worker->getHidden(TurnContext::TURN_ID_KEY))->toBeNull()
        ->and($worker->getHidden(TurnContext::TURN_META_KEY))->toBeNull()
        // The live turn keeps them.
        ->and($spend->pendingGenerationIds())->toBe(['gen-2'])
        ->and($spend->totalCost())->toEqualWithDelta(0.5, 1e-9)
        ->and(TurnContext::turnId())->toBe('r7');

    TurnContext::endTurn();
});

it('keeps the live turn\'s spend, turn id and meta through a job run in-process mid-turn', function () {
    // The real queue, on the sync connection (both apps' phpunit default).
    config()->set('queue.default', 'sync');
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');

    icChat(icSaveStep(), icAnswerStep('gen-final', 0.003));

    RememberingNoteAgent::$afterSave = function () {
        dispatch(new IcSyncProbeJob);

        $GLOBALS['icAfterSync'] = [
            'cost' => app(SpendCollector::class)->totalCost(),
            'turn_id' => TurnContext::turnId(),
            'meta' => TurnContext::turnMeta(),
        ];
    };

    icRun('r10', meta: ['user_id' => 7]);

    expect($GLOBALS['icSyncRan'] ?? false)->toBeTrue()
        ->and($GLOBALS['icAfterSync']['cost'])->toEqualWithDelta(0.001, 1e-9)
        ->and($GLOBALS['icAfterSync']['turn_id'])->toBe('r10')
        ->and($GLOBALS['icAfterSync']['meta'])->toBe(['user_id' => 7])
        ->and(UsageEvent::sole()->cost_usd)->toEqualWithDelta(0.004, 1e-9);
});

it('leaves the shared collector to the outer run when a nested run fails', function () {
    icChat(icSaveStep());
    $GLOBALS['icChat'][] = Http::response('upstream down', 502);
    icChat(icAnswerStep('gen-final', 0.003));

    // A sub-agent called from the tool fails; like laravel/ai's AgentTool,
    // the tool turns that into a result and the outer run carries on.
    RememberingNoteAgent::$afterSave = function () {
        try {
            (new RememberingNoteAgent)->forUser((object) ['id' => 7])->prompt('nested', provider: 'openrouter', model: 'test/model');
        } catch (ProviderOverloadedException) {
        }
    };

    expect(icRun('r11')->failed)->toBeFalse();

    $failed = UsageEvent::query()->where('status', 'failed')->sole();
    $outer = UsageEvent::query()->where('status', 'ok')->sole();

    expect($failed->cost_usd)->toBeNull()
        ->and($failed->context)->toMatchArray(['nested' => true])
        // The outer turn keeps its completed step: billed with the turn.
        ->and($outer->cost_usd)->toEqualWithDelta(0.004, 1e-9)
        ->and($outer->generation_ids)->toBe(['gen-save', 'gen-final']);
});

it('prices the cut-off step even when an app listener on TurnUsageRecorded throws', function () {
    Event::listen(TurnUsageRecorded::class, fn () => throw new RuntimeException('app listener broke'));

    icChat(icAnswerStep());
    icRun('r12', stopAtOnce: true);

    Queue::assertPushed(ResolveInterruptedSpend::class, 1);
});

it('keys each settlement of one turn separately', function () {
    $turn = fn (string $invocation) => new UsageEvent(['invocation_id' => $invocation, 'status' => 'stopped']);

    $a = new InterruptedSpendResolved(new UsageEvent, $turn('inv-a'), 't1', 0.01, ['g1']);
    $b = new InterruptedSpendResolved(new UsageEvent, $turn('inv-b'), 't1', 0.01, ['g2']);
    $bare = new InterruptedSpendResolved(new UsageEvent, $turn('inv-c'), null, 0.01, ['g3']);

    expect($a->debitKey())->toBe('debit:turn:t1:interrupted:inv-a')
        ->and($b->debitKey())->toBe('debit:turn:t1:interrupted:inv-b')
        ->and($bare->debitKey())->toBe('debit:turn:inv-c:interrupted:inv-c');
});

it('updates a reused delta row to what the retried event reports', function () {
    icChat(icAnswerStep());
    icRun('r13', stopAtOnce: true);

    $row = UsageEvent::sole();
    $spend = app(InterruptedSpend::class);

    $GLOBALS['icThrowOnce'] = true;
    Event::listen(InterruptedSpendResolved::class, function () {
        if ($GLOBALS['icThrowOnce']) {
            $GLOBALS['icThrowOnce'] = false;

            throw new RuntimeException('ledger down');
        }
    });

    expect(fn () => $spend->finish($row->id, ['g1' => 0.01], ['g1', 'g2'], 'r13', []))->toThrow(RuntimeException::class);

    expect($spend->finish($row->id, ['g1' => 0.01, 'g2' => 0.02], ['g1', 'g2'], 'r13', []))->toBe(InterruptedSpend::FIRED);

    $delta = UsageEvent::query()->where('status', 'resolved')->sole();

    expect($delta->cost_usd)->toEqualWithDelta(0.03, 1e-9)
        ->and($delta->generation_ids)->toBe(['g1', 'g2'])
        ->and(end($GLOBALS['icResolved'])->costUsd)->toEqualWithDelta(0.03, 1e-9);
});

it('announces a stop on a stream it cannot throw into', function () {
    $GLOBALS['icStopped'] = [];
    Event::listen(TurnStopped::class, function (TurnStopped $event) {
        $GLOBALS['icStopped'][] = $event->invocationId;
    });

    $buffer = new SpyTurnBuffer;
    $buffer->start('r14');
    $buffer->cancel('r14');

    $events = new ArrayIterator([
        (new StreamStart('s1', 'openrouter', 'test/model', time()))->withInvocationId('inv-plain'),
        (new TextDelta('d1', 'm1', 'Hi', time()))->withInvocationId('inv-plain'),
    ]);

    expect(app(TurnRunner::class)->run('r14', fn () => $events, new StreamEventMapper, $buffer)->cancelled)->toBeTrue()
        ->and($GLOBALS['icStopped'])->toBe(['inv-plain']);
});

it('reads a zero cost with counted tokens as "not yet" until the last attempt', function () {
    $resolver = app(GenerationCostResolver::class);

    $GLOBALS['icGeneration'][] = Http::response(['data' => ['total_cost' => 0, 'tokens_prompt' => 900, 'tokens_completion' => 12]]);
    $GLOBALS['icGeneration'][] = Http::response(['data' => ['total_cost' => 0, 'tokens_prompt' => 900, 'tokens_completion' => 12]]);
    $GLOBALS['icGeneration'][] = Http::response(['data' => ['total_cost' => 0, 'tokens_prompt' => 0, 'tokens_completion' => 0]]);

    expect($resolver->fetch('gen-z'))->toBeNull()
        ->and($resolver->fetch('gen-z', acceptZero: true))->toBe(0.0)
        ->and($resolver->fetch('gen-empty'))->toBe(0.0);
});

it('never queues delayed attempts on a connection that cannot hold them', function (array $connection, bool $queueable) {
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.connections.probe', $connection);
    config()->set('ai-kit.spend.connection', 'probe');
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');

    expect(app(InterruptedSpend::class)->queueable())->toBe($queueable);
})->with([
    'sync' => [['driver' => 'sync'], false],
    'deferred' => [['driver' => 'deferred'], false],
    'background' => [['driver' => 'background'], false],
    'null' => [['driver' => 'null'], false],
    'failover onto sync' => [['driver' => 'failover', 'connections' => ['sync']], false],
    'database' => [['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'], true],
]);

it('settles without queueing on a sync connection', function () {
    Log::spy();
    config()->set('queue.default', 'sync');
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');

    icChat(icAnswerStep());
    icRun('r8', stopAtOnce: true);

    // Nothing ran the attempts back-to-back inside the turn.
    expect($GLOBALS['icLookups'])->toBe([]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($context['reason'] ?? '', 'cannot hold a delayed job'));
});

it('records no spend of its own and prices nothing while the app drains the collector itself', function () {
    config()->set('ai-kit.usage.drain_spend', false);

    icChat(icSaveStep(), OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'I saved your no'], id: 'gen-died'),
        ['id' => 'gen-died', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
    ]));

    icRun('r9');

    expect(UsageEvent::sole()->status)->toBe('failed')
        ->and(UsageEvent::sole()->cost_usd)->toBeNull();

    Queue::assertNothingPushed();
});

it('caps the inline window by the time left, however many ids', function () {
    $resolver = new GenerationCostResolver(app(HttpFactory::class), 'k', windowSeconds: 0.0);

    expect($resolver->resolveMany(['a', 'b', 'c']))->toBe([])
        ->and($GLOBALS['icLookups'])->toBe([]);
});

class IcSyncProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        $GLOBALS['icSyncRan'] = true;
    }
}

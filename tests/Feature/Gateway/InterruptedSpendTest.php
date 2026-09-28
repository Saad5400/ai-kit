<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Saad\AiKit\Gateway\GenerationCostResolver;
use Saad\AiKit\Gateway\InterruptedSpend;
use Saad\AiKit\Gateway\InterruptedSpendHandler;
use Saad\AiKit\Gateway\ResolveInterruptedSpend;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Streaming\TurnRunner;
use Saad\AiKit\Tests\Support\OpenRouterSse;
use Saad\AiKit\Tests\Support\RememberingNoteAgent;
use Saad\AiKit\Tests\Support\SpyTurnBuffer;

/**
 * A stopped or failed turn's spend, end to end: a real remembering agent
 * streaming through the kit's gateway over faked HTTP, TurnRunner in front,
 * then InterruptedSpend pricing the cut-off step from OpenRouter's
 * `/generation` endpoint (also faked), the budget, and the app handler.
 */
uses(RefreshDatabase::class);

class RecordingInterruptedSpendHandler implements InterruptedSpendHandler
{
    /** @var list<array{turnId: string, costUsd: float, generationIds: list<string>, context: array}> */
    public array $calls = [];

    public ?Throwable $throwOnce = null;

    public function resolved(string $turnId, float $costUsd, array $generationIds, array $context): void
    {
        if ($this->throwOnce !== null) {
            [$e, $this->throwOnce] = [$this->throwOnce, null];

            throw $e;
        }

        $this->calls[] = compact('turnId', 'costUsd', 'generationIds', 'context');
    }
}

beforeEach(function () {
    config()->set('ai.conversations.generate_title', false);
    config()->set('ai-kit.gateway.retry.attempts', 1);
    config()->set('ai-kit.gateway.circuit_breaker.enabled', false);
    config()->set('ai-kit.safety.daily_usd_limit', 100.0);

    RememberingNoteAgent::$saved = [];
    RememberingNoteAgent::$afterSave = null;

    Carbon::setTestNow(Carbon::now());
    Sleep::fake();

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

    $this->handler = new RecordingInterruptedSpendHandler;
    app()->instance(InterruptedSpendHandler::class, $this->handler);
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
function icAnswerStep(string $id = 'gen-answer'): string
{
    return OpenRouterSse::body([
        OpenRouterSse::chunk(['content' => 'The answer is '], id: $id),
        OpenRouterSse::chunk(['content' => 'forty-two.'], id: $id),
        OpenRouterSse::chunk([], finishReason: 'stop', id: $id),
        OpenRouterSse::usageFrame(['prompt_tokens' => 900, 'completion_tokens' => 400, 'cost' => 0.0123], id: $id),
    ]);
}

function icStream(): mixed
{
    return (new RememberingNoteAgent)->forUser((object) ['id' => 7])
        ->stream('what is the answer?', provider: 'openrouter', model: 'test/model');
}

/** Run a turn the user stopped on its very first event. */
function icStoppedTurn(): void
{
    $buffer = new SpyTurnBuffer;
    $buffer->start('t1');
    $buffer->cancel('t1');

    $outcome = app(TurnRunner::class)->run('t1', fn () => icStream(), new StreamEventMapper, $buffer);

    expect($outcome->cancelled)->toBeTrue();
}

it('prices a single-step answer stopped mid-stream on the second poll, and hands it over once', function () {
    icChat(icAnswerStep());
    icStoppedTurn();

    $spend = app(SpendCollector::class);

    // The stop landed before the usage frame: nothing priced, one pending id.
    expect($spend->pendingGenerationIds())->toBe(['gen-answer'])
        ->and($spend->totalCost())->toBe(0.0);

    icNotYet();
    icPriced('gen-answer', 0.0123);

    Queue::fake();

    $report = InterruptedSpend::dispatchFor('t1', ['outcome' => 'stopped', 'user_id' => 7]);

    expect($GLOBALS['icLookups'])->toBe(['gen-answer', 'gen-answer'])
        ->and($report->handled)->toBeTrue()
        ->and($report->queued)->toBeFalse()
        ->and($report->pendingGenerationIds)->toBe(['gen-answer'])
        ->and($report->knownCostUsd())->toEqualWithDelta(0.0123, 1e-9)
        ->and($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['turnId'])->toBe('t1')
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.0123, 1e-9)
        ->and($this->handler->calls[0]['generationIds'])->toBe(['gen-answer'])
        ->and($this->handler->calls[0]['context'])->toBe(['outcome' => 'stopped', 'user_id' => 7])
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.0123, 1e-6);

    // Drained: the next turn on this worker starts clean.
    expect($spend->pendingGenerationIds())->toBe([]);

    Queue::assertNothingPushed();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://openrouter.ai/api/v1/generation?id=gen-answer')
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

it('queues the rest when the fast path cannot price it, and the job prices it on its next poll', function () {
    config()->set('ai-kit.spend.sync_backoff_ms', []); // the fast path: one look

    icChat(icAnswerStep());
    icStoppedTurn();

    Queue::fake();

    icNotYet();

    $report = InterruptedSpend::dispatchFor('t1', ['outcome' => 'stopped']);

    expect($report->queued)->toBeTrue()
        ->and($report->handled)->toBeFalse()
        ->and($report->unresolvedGenerationIds)->toBe(['gen-answer'])
        ->and($this->handler->calls)->toBe([]);

    $job = null;

    Queue::assertPushed(ResolveInterruptedSpend::class, function (ResolveInterruptedSpend $pushed) use (&$job) {
        $job = $pushed;

        return $pushed->delay === 10 && $pushed->attempt === 1 && $pushed->pendingGenerationIds === ['gen-answer'];
    });

    icPriced('gen-answer', 0.0123);

    app()->call([$job, 'handle']);

    expect($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.0123, 1e-9)
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.0123, 1e-6);

    Queue::assertPushed(ResolveInterruptedSpend::class, 1);
});

it('records a failed turn on the budget and hands the handler its completed steps plus the cut-off step', function () {
    icChat(
        // Step 0 completes (priced from its usage frame) and runs SaveNote…
        OpenRouterSse::body([
            OpenRouterSse::chunk(['content' => 'Saving it. '], id: 'gen-save'),
            OpenRouterSse::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_save', 'function' => ['name' => 'SaveNote', 'arguments' => '{"text":"buy milk"}']]]], finishReason: 'tool_calls', id: 'gen-save'),
            OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001], id: 'gen-save'),
        ]),
        // …step 1 dies mid-stream before its cost.
        OpenRouterSse::body([
            OpenRouterSse::chunk(['content' => 'I saved your no'], id: 'gen-died'),
            ['id' => 'gen-died', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
        ]),
    );

    $outcome = app(TurnRunner::class)->run('t2', fn () => icStream(), new StreamEventMapper, tap(new SpyTurnBuffer)->start('t2'));

    expect($outcome->failed)->toBeTrue()
        ->and(app(SpendCollector::class)->pendingGenerationIds())->toBe(['gen-died'])
        ->and(app(SpendCollector::class)->totalCost())->toEqualWithDelta(0.001, 1e-9);

    icPriced('gen-died', 0.002);

    $report = InterruptedSpend::dispatchFor('t2', ['outcome' => 'failed']);

    expect($report->settledCostUsd)->toEqualWithDelta(0.001, 1e-9)
        ->and($report->resolvedCostUsd)->toEqualWithDelta(0.002, 1e-9)
        // Failed turns count toward the budget even though apps do not debit them.
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.003, 1e-6)
        ->and($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.003, 1e-9)
        ->and($this->handler->calls[0]['generationIds'])->toBe(['gen-save', 'gen-died'])
        ->and($this->handler->calls[0]['context'])->toBe(['outcome' => 'failed']);
});

it('gives up after the configured attempts without throwing, handing over only what priced', function () {
    config()->set('ai-kit.spend.sync_backoff_ms', []);
    Log::spy();
    Queue::fake();

    $spend = app(SpendCollector::class);
    $spend->recordGenerationId('gen-done', streamed: true);
    $spend->recordCost(0.004, streamed: true);
    $spend->recordPendingGeneration('gen-lost');

    InterruptedSpend::dispatchFor('t3', ['outcome' => 'stopped']);

    $delays = [];
    $runs = 0;

    // Walk the chain: every run pushes its successor until the last gives up.
    while (($job = Queue::pushed(ResolveInterruptedSpend::class)->last()) !== null && $job->attempt > $runs) {
        $delays[] = $job->delay;
        $runs++;

        app()->call([$job, 'handle']);
    }

    expect($runs)->toBe(5)
        ->and($delays)->toBe([10, 30, 90, 180, 300])
        // 1 fast-path look + 1 per attempt; the endpoint never answered.
        ->and($GLOBALS['icLookups'])->toHaveCount(6)
        ->and($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.004, 1e-9)
        ->and($this->handler->calls[0]['generationIds'])->toBe(['gen-done'])
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.004, 1e-6);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['generation_ids'] === ['gen-lost'] && $context['attempts'] === 5);
});

it('never double-counts the budget or calls the handler twice when a job is redelivered', function () {
    config()->set('ai-kit.spend.sync_backoff_ms', []);
    Queue::fake();

    app(SpendCollector::class)->recordPendingGeneration('gen-x');

    icNotYet();
    InterruptedSpend::dispatchFor('t4', ['outcome' => 'stopped']);

    $job = Queue::pushed(ResolveInterruptedSpend::class)->first();

    icPriced('gen-x', 0.05);
    icPriced('gen-x', 0.05);

    // The same payload delivered twice (a worker crash after the handler ran).
    app()->call([$job, 'handle']);
    app()->call([clone $job, 'handle']);

    expect($this->handler->calls)->toHaveCount(1)
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.05, 1e-6);
});

it('lets a handler failure retry: the guard is released and the hand-over queued', function () {
    Queue::fake();

    app(SpendCollector::class)->recordPendingGeneration('gen-y');
    icPriced('gen-y', 0.01);

    $this->handler->throwOnce = new RuntimeException('ledger down');

    $report = InterruptedSpend::dispatchFor('t5', ['outcome' => 'stopped']);

    expect($report->handled)->toBeFalse()
        ->and($report->queued)->toBeTrue()
        ->and($this->handler->calls)->toBe([]);

    $job = Queue::pushed(ResolveInterruptedSpend::class)->first();

    expect($job->pendingGenerationIds)->toBe([])
        ->and($job->resolved)->toBe(['gen-y' => 0.01]);

    app()->call([$job, 'handle']);

    expect($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.01, 1e-9)
        // Budgeted once, on the fast path.
        ->and(app(BudgetGuard::class)->spentToday())->toEqualWithDelta(0.01, 1e-6)
        ->and($GLOBALS['icLookups'])->toBe(['gen-y']);
});

it('does nothing for a turn with no unmetered spend', function () {
    Queue::fake();

    $report = InterruptedSpend::dispatchFor('t6', ['outcome' => 'stopped']);

    expect($report->empty())->toBeTrue()
        ->and($this->handler->calls)->toBe([]);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('skips the pricing when disabled, still handing over what was already priced', function () {
    config()->set('ai-kit.spend.resolve_interrupted', false);
    Queue::fake();

    $spend = app(SpendCollector::class);
    $spend->recordCost(0.002, streamed: true);
    $spend->recordPendingGeneration('gen-z');

    InterruptedSpend::dispatchFor('t7');

    expect($this->handler->calls)->toHaveCount(1)
        ->and($this->handler->calls[0]['costUsd'])->toEqualWithDelta(0.002, 1e-9)
        ->and($spend->pendingGenerationIds())->toBe([]);

    Http::assertNothingSent();
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
    $resolver = new GenerationCostResolver(app(Factory::class), 'k', windowSeconds: 2.0, backoffMs: [500, 1000, 1500]);

    icNotYet(5);

    // 0.5 + 1.0 fit the 2s window, 1.5 more does not: three looks, then null.
    expect($resolver->resolve('gen-slow'))->toBeNull()
        ->and($GLOBALS['icLookups'])->toHaveCount(3);

    $GLOBALS['icGeneration'] = [];
    icPriced('gen-free', 0.0);

    expect($resolver->fetch('gen-free'))->toBe(0.0)
        ->and((new GenerationCostResolver(app(Factory::class), null))->fetch('gen-free'))->toBeNull();
});

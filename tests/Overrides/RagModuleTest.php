<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Saad\AiKit\Rag\Embeddings\Embedder;
use Saad\AiKit\Rag\Embeddings\FakeEmbedder;
use Saad\AiKit\Rag\Embeddings\OpenRouterEmbedder;
use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Rag\RagServiceProvider;
use Saad\AiKit\Rag\Rerank\FakeReranker;
use Saad\AiKit\Rag\Rerank\GuardedReranker;
use Saad\AiKit\Rag\Rerank\NullReranker;
use Saad\AiKit\Rag\Rerank\OpenRouterReranker;
use Saad\AiKit\Rag\Rerank\Reranker;
use Saad\AiKit\Rag\Rerank\RerankRequest;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Tests\RagEnabledTestCase;

uses(RagEnabledTestCase::class);

it('registers the rag module and binds both lanes to their drivers', function () {
    expect($this->app->providerIsLoaded(RagServiceProvider::class))->toBeTrue()
        ->and(app(Embedder::class))->toBeInstanceOf(OpenRouterEmbedder::class)
        ->and(app(Reranker::class))->toBeInstanceOf(GuardedReranker::class)
        ->and(app(Reranker::class)->driver())->toBeInstanceOf(OpenRouterReranker::class);
});

it('resolves the driver per make(), so a runtime config change (an eval variant) is honoured', function () {
    config(['ai-kit.embeddings.driver' => 'fake', 'ai-kit.embeddings.dimensions' => 8, 'ai-kit.rerank.driver' => 'fake']);

    expect(app(Embedder::class))->toBeInstanceOf(FakeEmbedder::class)
        ->and(app(Embedder::class)->dimensions())->toBe(8)
        ->and(app(Reranker::class)->driver())->toBeInstanceOf(FakeReranker::class);

    config(['ai-kit.rerank.driver' => 'null']);

    expect(app(Reranker::class)->driver())->toBeInstanceOf(NullReranker::class);
});

it('lets an app bind its own backend over the lane', function () {
    $this->app->bind(Embedder::class, fn () => new FakeEmbedder(4, 'app/custom'));

    expect(app(Embedder::class)->geometry())->toBe('app/custom:4');
});

it('refuses an unknown driver name loudly', function () {
    config(['ai-kit.rerank.driver' => 'cohere-direct']);

    app(Reranker::class);
})->throws(InvalidArgumentException::class, 'cohere-direct');

it('adds provider-reported retrieval spend to the daily budget, and only that', function () {
    $budget = app(BudgetGuard::class);
    $before = $budget->spentToday();

    event(new RetrievalSpend('rerank', 'cohere/rerank-v3.5', 1, 50, 0, 0.002, 300));
    event(new RetrievalSpend('embeddings', 'openai/text-embedding-3-small', 1, 1, 9, null, 40));

    expect($budget->spentToday())->toEqualWithDelta($before + 0.002, 1e-9);

    config(['ai-kit.rag.record_budget' => false]);
    event(new RetrievalSpend('rerank', 'cohere/rerank-v3.5', 1, 50, 0, 0.5, 300));

    expect($budget->spentToday())->toEqualWithDelta($before + 0.002, 1e-9);
});

it('ships reranking OFF: the bound lane answers `disabled` and sends nothing', function () {
    Http::fake();

    $responses = app(Reranker::class)->rerankMany([new RerankRequest('q', ['a', 'b'])]);

    expect(config('ai-kit.rerank.enabled'))->toBeFalse()
        ->and($responses[0]->failure)->toBe('disabled');
    Http::assertNothingSent();
});

it('sends a real lane call through the bound driver once an app enables it', function () {
    config(['ai-kit.rerank.enabled' => true]);
    Http::fake(['*/rerank' => Http::response(['results' => [['index' => 1, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.1]]])]);

    $scores = app(Reranker::class)->rerank('q', ['a', 'b']);

    expect(array_map(fn ($s) => $s->index, $scores))->toBe([1, 0]);
});

it('stops reranking with `budget` once the daily budget is exhausted', function () {
    config(['ai-kit.rerank.enabled' => true, 'ai-kit.safety.daily_usd_limit' => 0.001]);
    $this->app->forgetInstance(BudgetGuard::class);
    app(BudgetGuard::class)->record(0.002);
    Http::fake();

    expect(app(Reranker::class)->rerankMany([new RerankRequest('q', ['a'])])[0]->failure)->toBe('budget');
    Http::assertNothingSent();
});

it('logs a rerank whose reported cost broke the per-search ceiling', function () {
    Log::spy();

    event(new RetrievalSpend('rerank', 'cohere/rerank-v3.5', 2, 100, 0, 0.05, 300));
    event(new RetrievalSpend('rerank', 'cohere/rerank-v3.5', 2, 100, 0, 0.002, 300));

    Log::shouldHaveReceived('warning')->once();
});

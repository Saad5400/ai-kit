<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Rag\Rerank\FakeReranker;
use Saad\AiKit\Rag\Rerank\NullReranker;
use Saad\AiKit\Rag\Rerank\OpenRouterReranker;
use Saad\AiKit\Rag\Rerank\RerankFailed;
use Saad\AiKit\Rag\Rerank\RerankRequest;
use Saad\AiKit\Rag\Rerank\RerankScore;

function indexes(array $scores): array
{
    return array_map(fn (RerankScore $s) => $s->index, $scores);
}

it('ships cohere/rerank-v3.5 with an 800 ms budget as the lane default', function () {
    $reranker = OpenRouterReranker::fromConfig();

    expect($reranker->model())->toBe('cohere/rerank-v3.5')
        ->and(config('ai-kit.rerank.timeout_ms'))->toBe(800);
});

it('posts the Cohere-style body and returns scores best first', function () {
    Event::fake([RetrievalSpend::class]);
    Http::fake(['*/rerank' => Http::response([
        'results' => [['index' => 2, 'relevance_score' => 0.91], ['index' => 0, 'relevance_score' => 0.42], ['index' => 1, 'relevance_score' => 0.42]],
        'usage' => ['total_tokens' => 120, 'search_units' => 1, 'cost' => 0.001],
    ])]);

    $scores = OpenRouterReranker::fromConfig()->rerank('وش يعني multithreading', ['a', 'b', 'c']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/rerank'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->data() === ['model' => 'cohere/rerank-v3.5', 'query' => 'وش يعني multithreading', 'documents' => ['a', 'b', 'c']]);

    // Equal scores keep the incoming order.
    expect(indexes($scores))->toBe([2, 0, 1])
        ->and($scores[0]->score)->toBe(0.91);

    Event::assertDispatched(RetrievalSpend::class, fn (RetrievalSpend $e) => $e->lane === 'rerank'
        && $e->inputs === 3 && $e->tokens === 120 && $e->costUsd === 0.001 && ! $e->failed);
});

it('sends top_n when asked', function () {
    Http::fake(['*/rerank' => Http::response(['results' => [['index' => 0, 'relevance_score' => 0.5]]])]);

    OpenRouterReranker::fromConfig()->rerank('q', ['a', 'b'], topN: 1);

    Http::assertSent(fn (Request $request) => $request['top_n'] === 1);
});

it('runs many reranks concurrently, one failure never failing the others', function () {
    Http::fake(function (Request $request) {
        return match ($request['query']) {
            'ok' => Http::response(['results' => [['index' => 1, 'relevance_score' => 0.8], ['index' => 0, 'relevance_score' => 0.2]]]),
            'limited' => Http::response(['error' => 'rate'], 429),
            'garbled' => Http::response(['results' => [['index' => 9, 'relevance_score' => 0.1]]]),
        };
    });

    $responses = OpenRouterReranker::fromConfig()->rerankMany([
        new RerankRequest('ok', ['a', 'b']),
        new RerankRequest('limited', ['a']),
        new RerankRequest('empty', []),
        new RerankRequest('garbled', ['a']),
    ]);

    expect($responses)->toHaveCount(4)
        ->and(indexes($responses[0]->scores))->toBe([1, 0])
        ->and($responses[1]->failure)->toBe('http_429')
        ->and($responses[2]->ok())->toBeTrue()->and($responses[2]->scores)->toBe([])
        ->and($responses[3]->failure)->toBe('bad_response');

    Http::assertSentCount(3);
});

it('reports a timeout as a failure the caller can fall back from', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 800 milliseconds'));

    $responses = OpenRouterReranker::fromConfig()->rerankMany([new RerankRequest('q', ['a'])]);

    expect($responses[0]->failure)->toBe('timeout');

    OpenRouterReranker::fromConfig()->rerank('q', ['a']);
})->throws(RerankFailed::class, 'timeout');

it('fails without a key instead of sending an unauthenticated request', function () {
    Http::fake();
    config(['ai.providers.openrouter.key' => '']);

    expect(OpenRouterReranker::fromConfig()->rerankMany([new RerankRequest('q', ['a'])])[0]->failure)->toBe('no_api_key');
    Http::assertNothingSent();
});

it('scores the fake by shared folded terms, deterministically, and records requests', function () {
    $fake = new FakeReranker;

    $scores = $fake->rerank('محاضره الخامسة', ['مقدمة', 'المحاضرة الخامسة', 'محاضرة أولى']);

    expect(indexes($scores))->toBe([1, 2, 0])
        ->and($scores[0]->score)->toBe(1.0)
        ->and($fake->requests)->toHaveCount(1)
        ->and((new FakeReranker(fn ($q, $d, $i) => -$i))->rerank('q', ['a', 'b', 'c'], 2))->toHaveCount(2);
});

it('lets a test exercise the fallback path with a failing fake', function () {
    expect(FakeReranker::failing()->rerankMany([new RerankRequest('q', ['a'])])[0]->failure)->toBe('timeout');

    FakeReranker::failing('http_503')->rerank('q', ['a']);
})->throws(RerankFailed::class, 'http_503');

it('keeps the incoming order with the null reranker', function () {
    $scores = (new NullReranker)->rerank('q', ['a', 'b', 'c']);

    expect(indexes($scores))->toBe([0, 1, 2])
        ->and($scores[0]->score)->toBeGreaterThan($scores[1]->score)
        ->and((new NullReranker)->rerankMany([new RerankRequest('q', ['a', 'b'], 1)])[0]->scores)->toHaveCount(1);
});

<?php

use Saad\AiKit\Rag\Rerank\FakeReranker;
use Saad\AiKit\Rag\Rerank\GuardedReranker;
use Saad\AiKit\Rag\Rerank\RerankFailed;
use Saad\AiKit\Rag\Rerank\RerankRequest;

function guarded(string $model = 'cohere/rerank-v3.5', bool $enabled = true, ?float $ceiling = 0.01, ?FakeReranker $fake = null): GuardedReranker
{
    return new GuardedReranker($fake ?? new FakeReranker(model: $model), $enabled, $ceiling);
}

it('passes requests through when enabled and under the ceiling', function () {
    $fake = new FakeReranker(model: 'cohere/rerank-v3.5');

    $scores = guarded(fake: $fake)->rerank('محاضرة', ['مقدمة', 'المحاضرة الخامسة']);

    expect($scores[0]->index)->toBe(1)->and($fake->requests)->toHaveCount(1);
});

it('answers `disabled` without calling the driver when off', function () {
    $fake = new FakeReranker(model: 'cohere/rerank-v3.5');

    expect(guarded(enabled: false, fake: $fake)->rerankMany([new RerankRequest('q', ['a'])])[0]->failure)->toBe('disabled')
        ->and($fake->requests)->toBe([]);

    guarded(enabled: false)->rerank('q', ['a']);
})->throws(RerankFailed::class, 'disabled');

it('estimates Cohere search units: 50 short passages are one search', function () {
    $request = new RerankRequest('وش يعني multithreading', array_fill(0, 50, str_repeat('x', 900)));

    expect(guarded()->estimateUsd($request))->toBe(0.001)
        // 150 passages → 2 units; a 3,000-char passage is 3 pieces, so 70 of them → 210 pieces → 3 units.
        ->and(guarded()->estimateUsd(new RerankRequest('q', array_fill(0, 150, 'short'))))->toBe(0.002)
        ->and(guarded()->estimateUsd(new RerankRequest('q', array_fill(0, 70, str_repeat('x', 3000)))))->toBe(0.003);
});

it('estimates token-priced rerankers and leaves unpriced ones uncapped', function () {
    $request = new RerankRequest(str_repeat('q', 30), array_fill(0, 10, str_repeat('d', 300)));

    // (10 query tokens × 10 docs + 10 × 100 doc tokens) × $0.02/M
    expect(guarded('voyageai/rerank-2.5-lite')->estimateUsd($request))->toEqualWithDelta(1100 * 0.02 / 1e6, 1e-15)
        ->and(guarded('qwen/qwen3-reranker-8b')->estimateUsd($request))->toBeNull();
});

it('degrades only the over-ceiling search to `budget`, keeping alignment', function () {
    $fake = new FakeReranker(model: 'cohere/rerank-v3.5');
    $huge = array_fill(0, 2000, str_repeat('x', 1500)); // 6,000 pieces → 60 units → $0.06

    $responses = guarded(fake: $fake)->rerankMany([
        new RerankRequest('one', ['one']),
        new RerankRequest('two', $huge),
        new RerankRequest('three', ['three']),
    ]);

    expect($responses[0]->ok())->toBeTrue()
        ->and($responses[1]->failure)->toBe('budget')
        ->and($responses[2]->ok())->toBeTrue()
        ->and($responses[2]->scores[0]->score)->toBe(1.0)
        ->and(array_map(fn ($r) => $r->query, $fake->requests))->toBe(['one', 'three']);
});

it('applies no ceiling when none is configured', function () {
    $huge = array_fill(0, 2000, str_repeat('x', 1500));

    expect(guarded(ceiling: null)->rerankMany([new RerankRequest('q', $huge)])[0]->ok())->toBeTrue();
});

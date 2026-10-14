<?php

use Saad\AiKit\Rag\Fusion\ConvexCombination;
use Saad\AiKit\Rag\Fusion\MinMax;
use Saad\AiKit\Rag\Fusion\Rrf;
use Saad\AiKit\Rag\Fusion\Scores;

/*
 * The RRF expectations are catodemy's own (tests/Unit/Search/RrfTest.php on
 * origin/main): an app's Rrf must be able to delegate here as a no-op.
 */
it('keeps Cormack\'s K', function () {
    expect(Rrf::K)->toBe(60);
});

it('fuses ranks deterministically with key ascending as the tiebreak', function () {
    // lexical 1,2,3 — semantic 3,4. 3 = 1/63 + 1/61 · 1 = 1/61 · 2 = 1/62 · 4 = 1/62 (exact tie → 2 before 4).
    expect(Rrf::fuse(['lexical' => [1, 2, 3], 'semantic' => [3, 4]]))->toBe([3, 1, 2, 4]);
});

it('computes the exact reciprocal-rank scores, bit for bit', function () {
    $scores = Rrf::scores([[1, 2, 3], [3, 4]]);

    expect($scores[1])->toBe(1 / 61)
        ->and($scores[2])->toBe(1 / 62)
        ->and($scores[3])->toBe(1 / 63 + 1 / 61)
        ->and($scores[4])->toBe(1 / 62);
});

it('is stable regardless of list order and handles empty input', function () {
    expect(Rrf::fuse([[10, 20], [20, 30]]))->toBe(Rrf::fuse([[20, 30], [10, 20]]))
        ->and(Rrf::scores([]))->toBe([])
        ->and(Rrf::fuse([[2, 1]]))->toBe([2, 1]);
});

it('weights lists by name and counts a repeated key once, at its best rank', function () {
    $scores = Rrf::scores(['title' => ['a', 'b', 'a'], 'semantic' => ['b']], weights: ['title' => 2.0]);

    expect($scores['a'])->toBe(2.0 / 61)
        ->and($scores['b'])->toBe(2.0 / 62 + 1.0 / 61)
        ->and(Rrf::contribution(1, 60, 0.5))->toBe(0.5 / 61);
});

it('min-max normalises observed or theoretical ranges into [0, 1]', function () {
    expect(MinMax::normalize(['a' => 0.25, 'b' => 0.5, 'c' => 0.75]))->toBe(['a' => 0.0, 'b' => 0.5, 'c' => 1.0])
        ->and(MinMax::normalize(['a' => 0.3, 'b' => 0.6], floor: 0.0))->toBe(['a' => 0.5, 'b' => 1.0])
        ->and(MinMax::normalize(['only' => 0.42]))->toBe(['only' => 1.0])
        ->and(MinMax::normalize([]))->toBe([]);
});

it('fuses normalised scores by weight, a missing key scoring 0 in that list', function () {
    $scores = ConvexCombination::scores([
        'lexical' => ['a' => 10.0, 'b' => 5.0, 'c' => 0.0],
        'semantic' => ['b' => 0.9, 'd' => 0.3],
    ], weights: ['lexical' => 0.4, 'semantic' => 0.6]);

    // lexical norm: a 1, b .5, c 0 · semantic norm: b 1, d 0.
    expect(array_keys($scores))->toBe(['b', 'a', 'c', 'd'])
        ->and($scores['b'])->toEqualWithDelta(0.4 * 0.5 + 0.6, 1e-12)
        ->and($scores['a'])->toEqualWithDelta(0.4, 1e-12)
        ->and(ConvexCombination::fuse(['x' => ['p' => 2.0, 'q' => 4.0]], normalize: false))->toBe(['q', 'p']);
});

it('orders scores descending then by key ascending', function () {
    expect(Scores::keys([3 => 0.5, 1 => 0.5, 2 => 0.9]))->toBe([2, 1, 3]);
});

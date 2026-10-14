<?php

use Saad\AiKit\Rag\Eval\EvalReport;
use Saad\AiKit\Rag\Eval\Gate;
use Saad\AiKit\Rag\Eval\GoldenItem;
use Saad\AiKit\Rag\Eval\GoldenSet;
use Saad\AiKit\Rag\Eval\Metrics;
use Saad\AiKit\Rag\Eval\RunRecord;

/*
 * Parity with catodemy's search-review `eval_metrics.py`: the expected files
 * are that script's own output on this synthetic golden set + run
 * (`metrics.silver.json` = default flags; `metrics.all.k3.json` =
 * `--include-needs-label --k 3`). Synthetic on purpose — this repo is public.
 */
function evalFixture(string $file): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/rag/eval/'.$file);
}

function assertParity(EvalReport $report, array $python): void
{
    expect($report->k)->toBe($python['k'])
        ->and($report->nScored)->toBe($python['n_scored'])
        ->and($report->falsePositiveRate)->toEqualWithDelta($python['false_positive_rate_on_negatives'], 1e-12)
        ->and(array_keys($report->perQuery))->toBe(array_keys($python['per_query']));

    foreach ($python['per_query'] as $gid => $scores) {
        foreach ($scores as $metric => $value) {
            expect($report->perQuery[$gid][$metric])->toEqualWithDelta($value, 1e-12, "{$gid} {$metric}");
        }
    }

    foreach ($python['slices'] as $name => $slice) {
        expect($report->slice($name))->not->toBeNull("slice {$name}")
            ->and($report->slice($name)['n'])->toBe($slice['n']);

        foreach (['recall', 'hit1', 'mrr', 'ndcg'] as $metric) {
            // The script rounds slice means to 4 places.
            expect(round($report->slice($name)[$metric], 4))->toEqualWithDelta($slice[$metric], 1e-9, "{$name} {$metric}");
        }
    }
}

it('matches eval_metrics.py on the default flags', function () {
    $report = (new Metrics(gatedStatuses: ['silver']))->evaluate(
        GoldenSet::fromJsonl(evalFixture('golden.jsonl')),
        RunRecord::fromJsonl(evalFixture('run.jsonl')),
    );

    assertParity($report, json_decode(evalFixture('metrics.silver.json'), true));
});

it('matches eval_metrics.py with --include-needs-label --k 3', function () {
    $report = (new Metrics(k: 3, gatedStatuses: null))->evaluate(
        GoldenSet::fromJsonl(evalFixture('golden.jsonl')),
        RunRecord::fromJsonl(evalFixture('run.jsonl')),
    );

    assertParity($report, json_decode(evalFixture('metrics.all.k3.json'), true));
});

it('adds a course slice and scores gold by default', function () {
    $golden = GoldenSet::fromJsonl(evalFixture('golden.jsonl'));
    $report = (new Metrics)->evaluate($golden, RunRecord::fromJsonl(evalFixture('run.jsonl')));

    expect($report->slice('course:python')['n'])->toBe(1)
        ->and($report->slice('course:java')['n'])->toBe(9);
});

it('credits moments by window, lessons by family, everything else by kind', function () {
    $metrics = new Metrics;
    $moment = ['kind' => 'moment', 'id' => 'L', 'grade' => 2, 't_ms' => [60_000, 90_000]];

    expect($metrics->credit($moment, ['kind' => 'moment', 'id' => 'L', 't_ms' => 46_000]))->toBe(2)
        ->and($metrics->credit($moment, ['kind' => 'moment', 'id' => 'L', 't_ms' => 44_999]))->toBe(1)
        ->and($metrics->credit($moment, ['kind' => 'lesson', 'id' => 'L']))->toBe(1)
        ->and($metrics->credit($moment, ['kind' => 'file', 'id' => 'L']))->toBe(0)
        ->and($metrics->credit(['kind' => 'lesson', 'id' => 'L', 'grade' => 2], ['kind' => 'step', 'id' => 'L']))->toBe(2)
        ->and($metrics->credit(['kind' => 'lesson', 'id' => 'L', 'grade' => 2], ['kind' => 'moment', 'id' => 'L', 't_ms' => 1]))->toBe(2)
        ->and($metrics->credit(['kind' => 'file', 'id' => 'F', 'grade' => 1], ['kind' => 'file', 'id' => 'F']))->toBe(1)
        ->and($metrics->credit(['kind' => 'file', 'id' => '?', 'grade' => 1], ['kind' => 'file', 'id' => '?']))->toBe(0);
});

it('counts a negative as answered by the engine\'s confidence when it reports one', function () {
    $golden = GoldenSet::fromJsonl(json_encode(['gid' => 'n', 'relevant' => [], 'expect_empty' => true]));

    $quiet = (new Metrics)->evaluate($golden, [new RunRecord('n', [['kind' => 'lesson', 'id' => 'x']], confident: false)]);
    $loud = (new Metrics)->evaluate($golden, [new RunRecord('n', [['kind' => 'lesson', 'id' => 'x']])]);

    expect($quiet->falsePositiveRate)->toBe(0.0)->and($loud->falsePositiveRate)->toBe(1.0);
});

it('reads bulk items from `queries` or from the legacy JSON-in-query encoding', function () {
    $modern = GoldenItem::fromArray(['gid' => 'a', 'queries' => ['x', 'y'], 'relevant' => []]);
    $legacy = GoldenItem::fromArray(['gid' => 'b', 'query' => '["Java features","multithreaded"]', 'relevant' => []]);
    $single = GoldenItem::fromArray(['gid' => 'c', 'query' => '[1] is not a list query? no: it is', 'relevant' => [], 'layer' => 'tool']);

    expect($modern->queries)->toBe(['x', 'y'])->and($modern->isBulk())->toBeTrue()
        ->and($legacy->queries)->toBe(['Java features', 'multithreaded'])
        ->and($single->queries)->toBe(['[1] is not a list query? no: it is'])
        ->and($single->layer)->toBe('tool')
        ->and($modern->layer)->toBe('engine');
});

it('filters by layer when asked', function () {
    $golden = GoldenSet::fromJsonl(implode("\n", [
        json_encode(['gid' => 'e', 'relevant' => [['kind' => 'lesson', 'id' => 'L', 'grade' => 2]]]),
        json_encode(['gid' => 't', 'layer' => 'tool', 'relevant' => [['kind' => 'lesson', 'id' => 'L', 'grade' => 2]]]),
    ]));

    expect((new Metrics(layers: ['engine']))->evaluate($golden, [])->nScored)->toBe(1);
});

it('runs the app engine over the set and measures wall time', function () {
    $golden = GoldenSet::fromJsonl(evalFixture('golden.jsonl'));

    $records = $golden->run(fn (GoldenItem $item) => new RunRecord($item->gid, [['kind' => 'step', 'id' => 'L3']]));
    $report = (new Metrics)->evaluate($golden, $records, fingerprint: 'snap-1');

    expect($records)->toHaveCount(count($golden))
        ->and($records[0]->latencyMs)->toBeFloat()
        ->and($report->latency['n'])->toBe(count($golden))
        ->and($report->fingerprint)->toBe('snap-1')
        ->and(RunRecord::fromJsonl(RunRecord::toJsonl($records))[2]->hits)->toBe([['kind' => 'step', 'id' => 'L3']]);
});

it('round-trips a report through its baseline JSON and prints a table', function () {
    $report = (new Metrics)->evaluate(GoldenSet::fromJsonl(evalFixture('golden.jsonl')), RunRecord::fromJsonl(evalFixture('run.jsonl')));
    $again = EvalReport::fromArray(json_decode($report->toJson(), true));

    expect($again->slices)->toEqual($report->slices)
        ->and($again->falsePositiveRate)->toBe($report->falsePositiveRate)
        ->and($report->table())->toContain('intent:navigate')->toContain('negatives false-positive rate');
});

it('computes nearest-rank percentiles', function () {
    expect(EvalReport::percentiles([30.0, 10.0, 20.0, 40.0]))->toBe(['n' => 4, 'p50' => 20.0, 'p95' => 40.0])
        ->and(EvalReport::percentiles([])['p50'])->toBeNull();
});

function gateReport(float $recall, float $ndcg, ?float $fp = 0.0, array $intent = [], ?string $fingerprint = null): EvalReport
{
    $slices = ['all' => ['n' => 40, 'recall' => $recall, 'hit1' => 0.5, 'mrr' => 0.5, 'ndcg' => $ndcg]];

    foreach ($intent as $name => [$n, $r, $g]) {
        $slices["intent:{$name}"] = ['n' => $n, 'recall' => $r, 'hit1' => 0.5, 'mrr' => 0.5, 'ndcg' => $g];
    }

    return new EvalReport(10, 40, $fp, 3, $slices, [], fingerprint: $fingerprint);
}

it('passes a run that holds or improves', function () {
    $result = (new Gate)->check(gateReport(0.70, 0.60), gateReport(0.695, 0.65));

    expect($result->passed())->toBeTrue();
});

it('fails an overall drop, a big-slice drop and a rise in false positives', function () {
    $baseline = gateReport(0.70, 0.60, 0.0, ['navigate' => [10, 0.8, 0.7], 'tiny' => [2, 1.0, 1.0]]);
    $candidate = gateReport(0.68, 0.60, 0.34, ['navigate' => [10, 0.74, 0.7], 'tiny' => [2, 0.0, 0.0]]);

    $result = (new Gate)->check($baseline, $candidate);

    expect($result->passed())->toBeFalse()
        ->and($result->failures)->toHaveCount(3)
        ->and($result->failures[0])->toStartWith('all recall dropped')
        ->and($result->failures[1])->toStartWith('intent:navigate recall dropped')
        ->and($result->failures[2])->toStartWith('negatives false-positive rate rose');
});

it('refuses to compare runs from different snapshots', function () {
    $result = (new Gate)->check(gateReport(0.7, 0.6, fingerprint: 'a'), gateReport(0.9, 0.9, fingerprint: 'b'));

    expect($result->passed())->toBeFalse()
        ->and($result->refused)->toContain('fingerprint mismatch')
        ->and($result->failures)->toBe([]);
});

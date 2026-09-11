<?php

use Saad\AiKit\Bench\BenchReport;
use Saad\AiKit\Bench\BenchRunner;
use Saad\AiKit\Bench\DecisionPolicy;
use Saad\AiKit\Bench\Graders\NoNarration;
use Saad\AiKit\Bench\Graders\ToolsCalled;
use Saad\AiKit\Bench\ReportWriter;
use Saad\AiKit\Bench\RunOptions;
use Saad\AiKit\Bench\Turn;
use Saad\AiKit\Bench\Verdict;
use Saad\AiKit\Testing\FakeTurnDriver;
use Saad\AiKit\Tests\Support\BenchFixtures;

function benchReport(): BenchReport
{
    $driver = new FakeTurnDriver([
        // s.pass #1
        FakeTurnDriver::done('تم إنشاء المقرر «الفيزياء».', [['name' => 'upsert_course', 'arguments' => ['name' => 'الفيزياء'], 'result' => '{"id":1}']], 0.002),
        // s.pass #2
        FakeTurnDriver::done('تم إنشاء المقرر.', [['name' => 'upsert_course']], 0.001),
        // s.flaky #1 — fails ToolsCalled
        FakeTurnDriver::done('دعني أتحقق… لم أجد شيئاً.', [], 0.004),
        // s.flaky #2 — passes
        FakeTurnDriver::paused([['id' => 'a1', 'kind' => 'approval', 'title' => 'حذف المقرر']], costUsd: 0.003),
        FakeTurnDriver::done('تم الحذف.', [['name' => 'delete_course', 'arguments' => ['id' => 1], 'result' => 'ok']], 0.002),
        // s.crash #1 and #2 have no records → driver throws
    ]);

    return (new BenchRunner($driver))->run([
        BenchFixtures::scenario([new Turn('أنشئ مقرر الفيزياء')], [new ToolsCalled(all: ['UpsertCourse'])], ['name' => 's.pass', 'tags' => ['structure', 'ar']]),
        BenchFixtures::scenario([new Turn('احذف المقرر', onPause: DecisionPolicy::approveAll())], [new ToolsCalled(all: ['DeleteCourse']), new NoNarration], ['name' => 's.flaky', 'tags' => ['destructive']]),
        BenchFixtures::scenario([new Turn('x')], [], ['name' => 's.crash']),
    ], new RunOptions(attempts: 2, model: 'test/model'));
}

it('summarises totals, pass@k, pass rate and per-grader failures', function () {
    $summary = benchReport()->summary();
    $totals = $summary['totals'];

    expect($totals)->toMatchArray([
        'scenarios' => 3,
        'runs' => 6,
        'passed_runs' => 3,
        'failed_runs' => 3,
        'crashed_runs' => 2,
        'pass_rate' => 0.5,
        'k' => 2,
        'scenarios_passed_at_k' => 2,
        'warnings' => 1,
    ])
        ->and($totals['pass_at_k'])->toEqualWithDelta(2 / 3, 1e-3)
        ->and($totals['cost_usd'])->toEqualWithDelta(0.012, 1e-9);

    $byName = collect($summary['scenarios'])->keyBy('name');

    expect($byName['s.pass'])->toMatchArray(['attempts' => 2, 'passed' => 2, 'pass_at_k' => true, 'tags' => ['structure', 'ar']])
        ->and($byName['s.flaky'])->toMatchArray(['attempts' => 2, 'passed' => 1, 'pass_at_k' => true])
        ->and($byName['s.flaky']['failing_graders'])->toBe(['tools_called' => 1])
        ->and($byName['s.flaky']['warnings'])->toBe(['no_narration' => 1])
        ->and($byName['s.crash'])->toMatchArray(['attempts' => 2, 'passed' => 0, 'pass_at_k' => false])
        ->and($byName['s.crash']['crashes'])->toHaveCount(2)
        ->and($summary['grader_failures'])->toBe(['tools_called' => 1])
        ->and($summary['slowest'][0]['name'])->toBe('s.flaky')
        ->and($summary['most_expensive'][0]['cost_usd'])->toEqualWithDelta(0.005, 1e-9)
        ->and($summary['meta']['model'])->toBe('test/model');
});

it('emits JSON that round-trips Arabic and Markdown with the summary tables', function () {
    $report = benchReport();
    $json = $report->toJson();
    $decoded = json_decode($json, true);

    expect($json)->toContain('تم إنشاء المقرر «الفيزياء».')
        ->and($json)->not->toContain('\\u0')
        ->and($decoded['summary']['totals']['runs'])->toBe(6)
        ->and($decoded['runs'])->toHaveCount(6)
        ->and($decoded['runs'][0]['turns'][0]['toolCalls'][0]['name'])->toBe('upsert_course')
        ->and($decoded['runs'][0]['verdicts'][0]['grader'])->toBe('tools_called');

    $md = $report->toMarkdown();

    expect($md)->toContain('# AI Bench Report')
        ->toContain('| Scenario | Tags | Pass | Cost | Wall | Failing graders |')
        ->toContain('✅ `s.pass`')
        ->toContain('| structure, ar | 2/2 |')
        ->toContain('❌ `s.crash`')
        ->toContain('crash×2')
        ->toContain('## Failures by grader')
        ->toContain('| `tools_called` | 1 |')
        ->toContain('## Slowest runs')
        ->toContain('## Most expensive runs')
        ->toContain('Model override: `test/model`')
        ->toContain('سيناريو اختبار');
});

it('writes report.json, report.md and one transcript per run', function () {
    $dir = sys_get_temp_dir().'/ai-kit-bench-'.bin2hex(random_bytes(4));
    $report = benchReport();

    $paths = (new ReportWriter)->write($report, $dir);

    expect($paths['json'])->toBe("{$dir}/report.json")
        ->and($paths['markdown'])->toBe("{$dir}/report.md")
        ->and($paths['transcripts'])->toHaveCount(6)
        ->and($paths['transcripts'][0])->toEndWith('/transcripts/s.pass-1.md')
        ->and(file_exists($paths['json']))->toBeTrue()
        ->and(json_decode(file_get_contents($paths['json']), true)['summary']['totals']['runs'])->toBe(6);

    $flaky2 = file_get_contents("{$dir}/transcripts/s.flaky-2.md");

    expect($flaky2)->toContain('# سيناريو اختبار')
        ->toContain('`s.flaky` · attempt 2 · ✅ passed')
        ->toContain('## Turn 1')
        ->toContain('> احذف المقرر')
        ->toContain('**🃏 approval card** `a1`: حذف المقرر')
        ->toContain('### ↩ Resumed (decisions sent)')
        ->toContain('**🔧 delete_course**')
        ->toContain('"id": 1')
        ->toContain('> تم الحذف.')
        ->toContain('| `tools_called` | ✅ pass |');

    $crash = file_get_contents("{$dir}/transcripts/s.crash-1.md");
    expect($crash)->toContain('## 💥 Crash')->toContain('no scripted record left');

    $flaky1 = file_get_contents("{$dir}/transcripts/s.flaky-1.md");
    expect($flaky1)->toContain('| `tools_called` | ❌ fail |')->toContain('| `no_narration` | ⚠️ warn |')->toContain('<details><summary>Evidence</summary>');

    array_map('unlink', glob("{$dir}/transcripts/*"));
    rmdir("{$dir}/transcripts");
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

it('handles an empty report', function () {
    $report = new BenchReport([]);

    expect($report->summary()['totals']['pass_rate'])->toBeNull()
        ->and($report->summary()['totals']['pass_at_k'])->toBeNull()
        ->and($report->toMarkdown())->toContain('None.')
        ->and(json_decode($report->toJson(), true)['runs'])->toBe([]);
});

it('slugs scenario names for transcript file names', function () {
    $writer = new ReportWriter;

    expect($writer->slug('structure.create-course/three sections'))->toBe('structure.create-course-three-sections')
        ->and($writer->slug('سيناريو'))->toBe('scenario')
        ->and(Verdict::pass('x')->toArray()['status'])->toBe('pass');
});

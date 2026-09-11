<?php

use Saad\AiKit\Bench\BenchRunner;
use Saad\AiKit\Bench\DecisionPolicy;
use Saad\AiKit\Bench\RunOptions;
use Saad\AiKit\Bench\Scenario;
use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Turn;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;
use Saad\AiKit\Testing\FakeTurnDriver;
use Saad\AiKit\Tests\Support\BenchFixtures;

it('runs multi-turn scenarios continuing the conversation id and hands graders the context', function () {
    $driver = new FakeTurnDriver([
        FakeTurnDriver::done('تم إنشاء المقرر', [['name' => 'upsert_course']], 0.001, 'conv-A'),
        FakeTurnDriver::done('تمت إضافة ثلاث شعب', [['name' => 'upsert_section']], 0.002, 'conv-A'),
    ]);

    $seen = null;
    $scenario = BenchFixtures::scenario(
        [new Turn('أنشئ مقرر'), new Turn('أضف ثلاث شعب')],
        [BenchFixtures::grader('ctx', function (ScenarioRun $run) use (&$seen) {
            $seen = $run->context;

            return Verdict::pass('ctx');
        })],
    );

    $report = (new BenchRunner($driver))->run([$scenario], new RunOptions(model: 'x/model'));

    expect($report->runs)->toHaveCount(1);
    $run = $report->runs[0];
    expect($run->passed())->toBeTrue()
        ->and($run->turns)->toHaveCount(2)
        ->and($run->turnBoundaries)->toBe([0, 1])
        ->and($run->costUsd)->toEqualWithDelta(0.003, 1e-9)
        ->and($run->toolNames())->toBe(['upsert_course', 'upsert_section'])
        ->and($run->lastText())->toBe('تمت إضافة ثلاث شعب')
        ->and($run->allText())->toContain('تم إنشاء المقرر')
        ->and($seen)->toBe(['course_id' => 42]);

    expect($driver->calls[0]['conversationId'])->toBeNull()
        ->and($driver->calls[0]['model'])->toBe('x/model')
        ->and($driver->calls[1]['conversationId'])->toBe('conv-A')
        ->and($driver->calls[1]['prompt'])->toBe('أضف ثلاث شعب');
});

it('auto-resumes approval cards with approveAll and rejectAll', function () {
    foreach ([[DecisionPolicy::approveAll(), true], [DecisionPolicy::rejectAll(), false]] as [$policy, $approve]) {
        $driver = new FakeTurnDriver([
            FakeTurnDriver::paused([['id' => 'card-1', 'kind' => 'approval', 'title' => 'حذف الشعبة']]),
            FakeTurnDriver::done('تم'),
        ]);

        $run = (new BenchRunner($driver))->run([BenchFixtures::scenario([new Turn('احذف', onPause: $policy)])], new RunOptions)->runs[0];

        expect($run->turns)->toHaveCount(2)
            ->and($run->turns[0]->isPaused())->toBeTrue()
            ->and($driver->callsTo('decide'))->toHaveCount(1)
            ->and($driver->callsTo('decide')[0]['decisions'])->toBe(['card-1' => ['approve' => $approve]])
            ->and($driver->callsTo('decide')[0]['conversationId'])->toBe('conv-1')
            ->and($run->cards('approval'))->toHaveCount(1);
    }
});

it('answers question cards in order then falls back, approving approvals along the way', function () {
    $driver = new FakeTurnDriver([
        FakeTurnDriver::paused([['id' => 'q1', 'kind' => 'question', 'title' => 'أي فصل؟']]),
        FakeTurnDriver::paused([['id' => 'q2', 'kind' => 'question'], ['id' => 'a1', 'kind' => 'approval']]),
        FakeTurnDriver::paused([['id' => 'q3', 'kind' => 'question']]),
        FakeTurnDriver::done('تم'),
    ]);

    $policy = DecisionPolicy::answer(['الفصل الأول', '٢٠٢٦']);
    $run = (new BenchRunner($driver))->run([BenchFixtures::scenario([new Turn('أنشئ شعبة', onPause: $policy)])], new RunOptions)->runs[0];

    $decides = $driver->callsTo('decide');
    expect($decides)->toHaveCount(3)
        ->and($decides[0]['decisions'])->toBe(['q1' => ['answer' => 'الفصل الأول']])
        ->and($decides[1]['decisions'])->toBe(['q2' => ['answer' => '٢٠٢٦'], 'a1' => ['approve' => true]])
        ->and($decides[2]['decisions']['q3']['answer'])->toBe($policy->fallbackAnswer)
        ->and($run->turns)->toHaveCount(4)
        ->and($run->lastTurn()->isDone())->toBeTrue();
});

it('leaves the turn paused under DecisionPolicy::none and skips later turns', function () {
    $driver = new FakeTurnDriver([
        FakeTurnDriver::paused([['id' => 'a1', 'kind' => 'approval']]),
    ]);

    $run = (new BenchRunner($driver))->run([BenchFixtures::scenario([
        new Turn('احذف المقرر', onPause: DecisionPolicy::none()),
        new Turn('never sent'),
    ])], new RunOptions)->runs[0];

    expect($run->turns)->toHaveCount(1)
        ->and($run->lastTurn()->isPaused())->toBeTrue()
        ->and($driver->callsTo('decide'))->toBe([])
        ->and($driver->callsTo('run'))->toHaveCount(1)
        ->and($run->failure)->toBeNull()
        ->and($run->passed())->toBeTrue();
});

it('uses the custom policy closure and caps resume rounds at maxRounds', function () {
    $driver = (new FakeTurnDriver)->respondWith(fn (string $method) => FakeTurnDriver::paused([['id' => 'q', 'kind' => 'question']]));

    $seen = 0;
    $policy = DecisionPolicy::custom(function (TurnRecord $paused) use (&$seen): array {
        $seen++;

        return ['q' => ['answer' => 'custom-'.$seen]];
    })->withMaxRounds(2);

    $run = (new BenchRunner($driver))->run([BenchFixtures::scenario([new Turn('loop', onPause: $policy)])], new RunOptions)->runs[0];

    expect($seen)->toBe(2)
        ->and($driver->callsTo('decide'))->toHaveCount(2)
        ->and($driver->callsTo('decide')[1]['decisions'])->toBe(['q' => ['answer' => 'custom-2']])
        ->and($run->turns)->toHaveCount(3)
        ->and($run->lastTurn()->isPaused())->toBeTrue();
});

it('stops the bench when the total budget is exceeded and records the reason', function () {
    $driver = (new FakeTurnDriver)->respondWith(fn () => FakeTurnDriver::done('ok', [], 0.30));

    $report = (new BenchRunner($driver))->run([
        BenchFixtures::scenario([new Turn('a'), new Turn('b')], extra: ['name' => 's.one']),
        BenchFixtures::scenario([new Turn('c')], extra: ['name' => 's.two']),
    ], new RunOptions(maxTotalCostUsd: 0.50));

    expect($report->runs)->toHaveCount(1)
        ->and($report->runs[0]->turns)->toHaveCount(2)
        ->and($report->runs[0]->failedVerdicts()[0]->grader)->toBe('bench.budget')
        ->and($report->meta['stopped_reason'])->toContain('Budget exhausted')
        ->and($report->meta['stopped_reason'])->toContain('s.two');
});

it('does not stop on budget when stopOnBudget is off', function () {
    $driver = (new FakeTurnDriver)->respondWith(fn () => FakeTurnDriver::done('ok', [], 0.30));

    $report = (new BenchRunner($driver))->run([
        BenchFixtures::scenario([new Turn('a')], extra: ['name' => 's.one']),
        BenchFixtures::scenario([new Turn('c')], extra: ['name' => 's.two']),
    ], new RunOptions(maxTotalCostUsd: 0.10, stopOnBudget: false));

    expect($report->runs)->toHaveCount(2)
        ->and($report->meta['stopped_reason'])->toBeNull();
});

it('isolates a driver crash into the run failure and keeps benching', function () {
    $driver = new FakeTurnDriver([FakeTurnDriver::done('fine')]);
    $torn = [];

    $report = (new BenchRunner($driver))->run([
        BenchFixtures::scenario([new Turn('a')], extra: ['name' => 's.ok', 'teardown' => function ($ctx) use (&$torn) {
            $torn[] = 'ok';
        }]),
        BenchFixtures::scenario([new Turn('b')], extra: ['name' => 's.crash', 'teardown' => function ($ctx) use (&$torn) {
            $torn[] = 'crash';
        }]),
        BenchFixtures::scenario([new Turn('c')], extra: ['name' => 's.no-actor', 'actor' => null]),
    ], new RunOptions);

    expect($report->runs)->toHaveCount(3)
        ->and($report->runs[0]->passed())->toBeTrue()
        ->and($report->runs[1]->passed())->toBeFalse()
        ->and($report->runs[1]->failure)->toContain('no scripted record left')
        ->and($report->runs[2]->failure)->toContain('defines no actor closure')
        ->and($torn)->toBe(['ok', 'crash']);
});

it('turns a grader exception into a failed verdict and runs turn-scoped graders after each turn', function () {
    $driver = new FakeTurnDriver([FakeTurnDriver::done('one'), FakeTurnDriver::done('two')]);

    $turnGrader = BenchFixtures::grader('turn.sees', fn (ScenarioRun $run) => Verdict::pass('turn.sees', (string) count($run->turns)));
    $throwing = BenchFixtures::grader('boom', fn () => throw new LogicException('kaboom'));

    $run = (new BenchRunner($driver))->run([BenchFixtures::scenario(
        [new Turn('a', graders: [$turnGrader]), new Turn('b', graders: [$turnGrader])],
        [$throwing],
    )], new RunOptions)->runs[0];

    $names = array_map(fn (Verdict $v) => [$v->grader, $v->status, $v->message], $run->verdicts);

    expect($names[0])->toBe(['turn.sees', 'pass', '1'])
        ->and($names[1])->toBe(['turn.sees', 'pass', '2'])
        ->and($names[2][0])->toBe('boom')
        ->and($names[2][1])->toBe('fail')
        ->and($names[2][2])->toContain('kaboom')
        ->and($run->passed())->toBeFalse();
});

it('stops after an errored turn and adds built-in cost and wall verdicts', function () {
    $driver = new FakeTurnDriver([FakeTurnDriver::errored('provider down')]);

    $run = (new BenchRunner($driver))->run([BenchFixtures::scenario(
        [new Turn('a'), new Turn('b')],
        extra: ['maxCostUsd' => 0.01, 'maxWallMs' => 1],
    )], new RunOptions)->runs[0];

    $byName = collect($run->verdicts)->keyBy('grader');

    expect($driver->callsTo('run'))->toHaveCount(1)
        ->and($byName[BenchRunner::BUILTIN_COST_GRADER]->status)->toBe('pass')
        ->and($byName[BenchRunner::BUILTIN_WALL_GRADER]->status)->toBe('fail');
});

it('repeats attempts and calls the lifecycle hooks in order', function () {
    $driver = (new FakeTurnDriver)->respondWith(fn () => FakeTurnDriver::done('ok'));
    $log = [];

    $report = (new BenchRunner($driver))->run([BenchFixtures::scenario([new Turn('a')])], new RunOptions(
        attempts: 3,
        beforeScenario: function (Scenario $s, int $attempt) use (&$log) {
            $log[] = "before:$attempt";
        },
        afterScenario: function (Scenario $s, int $attempt, ScenarioRun $run) use (&$log) {
            $log[] = "after:$attempt";
        },
        onScenarioDone: function (ScenarioRun $run) use (&$log) {
            $log[] = "done:{$run->attempt}";
        },
    ));

    expect($report->runs)->toHaveCount(3)
        ->and(array_map(fn ($r) => $r->attempt, $report->runs))->toBe([1, 2, 3])
        ->and($log)->toBe(['before:1', 'after:1', 'done:1', 'before:2', 'after:2', 'done:2', 'before:3', 'after:3', 'done:3']);
});

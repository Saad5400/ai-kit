<?php

use Saad\AiKit\Bench\Graders\AskedAtMost;
use Saad\AiKit\Bench\Graders\Callback;
use Saad\AiKit\Bench\Graders\CostUnder;
use Saad\AiKit\Bench\Graders\JudgeAgent;
use Saad\AiKit\Bench\Graders\LanguageMatches;
use Saad\AiKit\Bench\Graders\LlmJudge;
use Saad\AiKit\Bench\Graders\NoNarration;
use Saad\AiKit\Bench\Graders\NoPlaceholders;
use Saad\AiKit\Bench\Graders\NoProviderMarkup;
use Saad\AiKit\Bench\Graders\NoRepeatedReads;
use Saad\AiKit\Bench\Graders\NoStepExhaustion;
use Saad\AiKit\Bench\Graders\PausedFor;
use Saad\AiKit\Bench\Graders\ReplyNotEmpty;
use Saad\AiKit\Bench\Graders\TextContains;
use Saad\AiKit\Bench\Graders\TextMatches;
use Saad\AiKit\Bench\Graders\TextNotContains;
use Saad\AiKit\Bench\Graders\ToolCallsAtMost;
use Saad\AiKit\Bench\Graders\ToolErrorsAtMost;
use Saad\AiKit\Bench\Graders\ToolsCalled;
use Saad\AiKit\Bench\Graders\WallUnder;
use Saad\AiKit\Bench\Support\ToolName;
use Saad\AiKit\Bench\Verdict;
use Saad\AiKit\Testing\FakeTurnDriver;
use Saad\AiKit\Tests\Support\BenchFixtures;

$done = fn (string $text, array $tools = [], ?float $cost = null) => FakeTurnDriver::done($text, $tools, $cost);

describe('ReplyNotEmpty', function () use ($done) {
    it('passes on text, on a paused card, and fails on blank', function () use ($done) {
        expect((new ReplyNotEmpty)->grade(BenchFixtures::run([$done('تم إنشاء المقرر.')]))->status)->toBe('pass')
            ->and((new ReplyNotEmpty)->grade(BenchFixtures::run([FakeTurnDriver::paused([['id' => 'a']])]))->status)->toBe('pass')
            ->and((new ReplyNotEmpty)->grade(BenchFixtures::run([$done('   ')]))->status)->toBe('fail')
            ->and((new ReplyNotEmpty)->grade(BenchFixtures::run([]))->status)->toBe('fail')
            ->and((new ReplyNotEmpty)->name())->toBe('reply_not_empty');
    });
});

describe('NoPlaceholders', function () use ($done) {
    it('fails on template tokens and passes on real links', function () use ($done) {
        $bad = $done('افتح الرابط {activity_url} أو [link] أو https://host/app أو https://example.com/x أو <id>');
        $verdict = (new NoPlaceholders)->grade(BenchFixtures::run([$bad]));

        expect($verdict->status)->toBe('fail')
            ->and($verdict->evidence['hits'])->toContain('{activity_url}', '[link]', 'https://host/', 'https://example.com', '<id>')
            ->and((new NoPlaceholders)->grade(BenchFixtures::run([$done('افتح https://app.sgrade.sa/app/courses/12 الآن')]))->status)->toBe('pass');
    });
});

describe('NoProviderMarkup', function () use ($done) {
    it('fails on leaked DSML / ChatML / think tokens', function () use ($done) {
        foreach (['<｜DSML｜function_calls>', '<|im_start|>assistant', 'x</think>y', '<tool_call>{"a":1}</tool_call>'] as $leak) {
            expect((new NoProviderMarkup)->grade(BenchFixtures::run([$done("تم. {$leak}")]))->status)->toBe('fail', $leak);
        }

        expect((new NoProviderMarkup)->grade(BenchFixtures::run([$done('تم إنشاء <b>المقرر</b> بنجاح')]))->status)->toBe('pass');
    });
});

describe('NoStepExhaustion', function () use ($done) {
    it('detects the sentinel in tool results and blank done-with-tools turns', function () use ($done) {
        $sentinel = $done('تم', [['name' => 'upsert_course', 'result' => 'Reached the maximum number of steps.']]);
        $blank = $done('', [['name' => 'list_records', 'result' => '[]']]);
        $fine = $done('تم', [['name' => 'list_records', 'result' => '[]']]);

        $v = (new NoStepExhaustion)->grade(BenchFixtures::run([$sentinel, $blank]));

        expect($v->status)->toBe('fail')
            ->and($v->evidence['findings'])->toHaveCount(2)
            ->and($v->evidence['findings'][1]['reason'])->toContain('blank trailing text')
            ->and((new NoStepExhaustion)->grade(BenchFixtures::run([$fine]))->status)->toBe('pass');
    });
});

describe('NoNarration', function () use ($done) {
    it('warns on AR and EN narration, fails when configured, ignores code', function () use ($done) {
        expect((new NoNarration)->grade(BenchFixtures::run([$done('دعني أتحقق من المقرر أولاً.')]))->status)->toBe('warn')
            ->and((new NoNarration)->grade(BenchFixtures::run([$done('سأقوم بإنشاء المقرر الآن')]))->status)->toBe('warn')
            ->and((new NoNarration)->grade(BenchFixtures::run([$done('Sure. Let me check the roster.')]))->status)->toBe('warn')
            ->and((new NoNarration(fail: true))->grade(BenchFixtures::run([$done("I'll start by listing courses")]))->status)->toBe('fail')
            ->and((new NoNarration)->grade(BenchFixtures::run([$done('تم إنشاء المقرر «الفيزياء» بثلاث شعب.')]))->status)->toBe('pass')
            ->and((new NoNarration)->grade(BenchFixtures::run([$done('Done. `Let me` is a string in the code above.')]))->status)->toBe('pass')
            ->and((new NoNarration(patterns: ['حسنًا']))->grade(BenchFixtures::run([$done('حسنًا، تم.')]))->status)->toBe('warn');
    });

    it('reads extended patterns from config', function () use ($done) {
        config()->set('ai-kit.bench.narration_patterns.ar', ['طيب دعني']);
        config()->set('ai-kit.bench.narration_fails', true);

        expect((new NoNarration)->grade(BenchFixtures::run([$done('طيب دعني أرى')]))->status)->toBe('fail')
            ->and((new NoNarration)->grade(BenchFixtures::run([$done('Let me see')]))->status)->toBe('fail');
    });
});

describe('LanguageMatches', function () use ($done) {
    it('passes a mostly-Arabic reply and fails a Latin one for an ar scenario', function () use ($done) {
        $ar = $done('تم إنشاء المقرر «الفيزياء ١٠١» وثلاث شعب. افتحه من https://app.example/courses/1 — الكود `PHY-101`.');
        $en = $done('Created the course Physics 101 with three sections. تم.');

        $pass = (new LanguageMatches)->grade(BenchFixtures::run([$ar]));
        $fail = (new LanguageMatches)->grade(BenchFixtures::run([$en]));

        expect($pass->status)->toBe('pass')
            ->and($pass->evidence['ratio'])->toBeGreaterThanOrEqual(0.7)
            ->and($fail->status)->toBe('fail')
            ->and((new LanguageMatches)->grade(BenchFixtures::run([$en], ['expectedLanguage' => 'en']))->status)->toBe('pass')
            ->and((new LanguageMatches)->grade(BenchFixtures::run([$ar], ['expectedLanguage' => null]))->status)->toBe('skip');
    });

    it('fails on glued mixed-script tokens even when the ratio is fine', function () use ($done) {
        $v = (new LanguageMatches)->grade(BenchFixtures::run([$done('تم إنشاء المقرر والشعب الثلاث بنجاح، Letني أعرف إن أردت المزيد من التفاصيل عن المقرر.')]));

        expect($v->status)->toBe('fail')
            ->and($v->evidence['glued'])->toContain('Letني');
    });

    it('passes when a paused turn has no prose', function () {
        expect((new LanguageMatches)->grade(BenchFixtures::run([FakeTurnDriver::paused([['id' => 'a']])]))->status)->toBe('pass');
    });
});

describe('ToolsCalled', function () use ($done) {
    it('normalizes class basenames and snake names on both sides', function () use ($done) {
        expect(ToolName::normalize('App\\Ai\\Tools\\UpsertCourse'))->toBe('upsert_course')
            ->and(ToolName::normalize('upsert_course'))->toBe('upsert_course')
            ->and(ToolName::normalize('list-records'))->toBe('list_records');

        $run = BenchFixtures::run([
            $done('a', [['name' => 'list_records'], ['name' => 'upsert_course']]),
            $done('b', [['name' => 'upsert_section']]),
        ]);

        expect((new ToolsCalled(any: ['UpsertCourse', 'delete_course']))->grade($run)->status)->toBe('pass')
            ->and((new ToolsCalled(all: ['UpsertCourse', 'UpsertSection']))->grade($run)->status)->toBe('pass')
            ->and((new ToolsCalled(all: ['UpsertCourse', 'DeleteCourse']))->grade($run)->message)->toContain('missing [delete_course]')
            ->and((new ToolsCalled(none: ['ListRecords']))->grade($run)->message)->toContain('forbidden [list_records]')
            ->and((new ToolsCalled(inOrder: ['UpsertCourse', 'UpsertSection']))->grade($run)->status)->toBe('pass')
            ->and((new ToolsCalled(inOrder: ['UpsertSection', 'UpsertCourse']))->grade($run)->status)->toBe('fail')
            ->and((new ToolsCalled(any: ['delete_course']))->grade($run)->status)->toBe('fail');
    });
});

describe('counts and limits', function () use ($done) {
    it('ToolCallsAtMost / ToolErrorsAtMost / NoRepeatedReads / AskedAtMost / PausedFor', function () use ($done) {
        $run = BenchFixtures::run([
            $done('a', [
                ['name' => 'list_records', 'arguments' => ['type' => 'course', 'q' => 'فيزياء'], 'result' => '[]'],
                ['name' => 'list_records', 'arguments' => ['q' => 'فيزياء', 'type' => 'course'], 'result' => '[]'],
                ['name' => 'upsert_course', 'arguments' => ['name' => 'x'], 'result' => 'Error: not permitted', 'error' => true],
                ['name' => 'upsert_course', 'arguments' => ['name' => 'y'], 'result' => 'Operation failed'],
            ]),
            FakeTurnDriver::paused([['id' => 'q1', 'kind' => 'question', 'title' => 'أي فصل؟'], ['id' => 'a1', 'kind' => 'approval']]),
            FakeTurnDriver::paused([['id' => 'q2', 'kind' => 'question']]),
        ]);

        expect((new ToolCallsAtMost(4))->grade($run)->status)->toBe('pass')
            ->and((new ToolCallsAtMost(3))->grade($run)->status)->toBe('fail')
            ->and((new ToolErrorsAtMost(0))->grade($run)->status)->toBe('fail')
            ->and((new ToolErrorsAtMost(0))->grade($run)->evidence['errors'])->toHaveCount(2)
            ->and((new ToolErrorsAtMost(0, flagOnly: true))->grade($run)->evidence['errors'])->toHaveCount(1)
            ->and((new ToolErrorsAtMost(2))->grade($run)->status)->toBe('pass')
            ->and((new ToolErrorsAtMost(0, markers: ['خطأ']))->grade($run)->evidence['errors'])->toHaveCount(1)
            ->and((new NoRepeatedReads)->grade($run)->status)->toBe('warn')
            ->and((new NoRepeatedReads(fail: true))->grade($run)->status)->toBe('fail')
            ->and((new NoRepeatedReads(readTools: ['UpsertCourse']))->grade($run)->status)->toBe('pass')
            ->and((new AskedAtMost(2))->grade($run)->status)->toBe('pass')
            ->and((new AskedAtMost(1))->grade($run)->status)->toBe('fail')
            ->and((new PausedFor('approval'))->grade($run)->status)->toBe('pass')
            ->and((new PausedFor('question', 2))->grade($run)->status)->toBe('pass')
            ->and((new PausedFor('approval', 2))->grade($run)->status)->toBe('fail')
            ->and((new PausedFor('question'))->name())->toBe('paused_for_question');
    });

    it('CostUnder / WallUnder', function () use ($done) {
        $run = BenchFixtures::run([$done('a', [], 0.004), $done('b', [], 0.003)]);

        expect($run->costUsd)->toEqualWithDelta(0.007, 1e-9)
            ->and((new CostUnder(0.01))->grade($run)->status)->toBe('pass')
            ->and((new CostUnder(0.005))->grade($run)->status)->toBe('fail')
            ->and((new WallUnder(20))->grade($run)->status)->toBe('pass')
            ->and((new WallUnder(19))->grade($run)->status)->toBe('fail');
    });
});

describe('text graders', function () use ($done) {
    it('TextContains / TextNotContains / TextMatches', function () use ($done) {
        $run = BenchFixtures::run([$done('تم إنشاء المقرر.'), $done('وأُضيفت ثلاث شعب: شعبة 01، شعبة 02، شعبة 03.')]);

        expect((new TextContains(['ثلاث شعب']))->grade($run)->status)->toBe('pass')
            ->and((new TextContains(['ثلاث شعب', 'المقرر'], all: true))->grade($run)->status)->toBe('pass')
            ->and((new TextContains(['شعبة 03'], lastOnly: true))->grade($run)->status)->toBe('pass')
            ->and((new TextContains(['إنشاء المقرر'], lastOnly: true))->grade($run)->status)->toBe('fail')
            ->and((new TextContains(['حذف', 'المقرر'], all: true))->grade($run)->message)->toContain('Missing: حذف')
            ->and((new TextNotContains(['حذف']))->grade($run)->status)->toBe('pass')
            ->and((new TextNotContains(['شعبة 02']))->grade($run)->status)->toBe('fail')
            ->and((new TextMatches('/شعبة 0[1-3]/u'))->grade($run)->status)->toBe('pass')
            ->and((new TextMatches('/شعبة 0[4-9]/u'))->grade($run)->status)->toBe('fail')
            ->and((new TextMatches('/[unclosed'))->grade($run)->message)->toContain('Invalid regex');
    });

    it('Callback maps Verdict, bool and string results under its own name', function () use ($done) {
        $run = BenchFixtures::run([$done('x')]);

        expect((new Callback('ctx.ok', fn ($r) => $r->context['course_id'] === 42))->grade($run)->status)->toBe('pass')
            ->and((new Callback('ctx.ok', fn () => false))->grade($run)->status)->toBe('fail')
            ->and((new Callback('ctx.ok', fn () => 'sections missing'))->grade($run)->message)->toBe('sections missing')
            ->and((new Callback('ctx.ok', fn () => ''))->grade($run)->status)->toBe('pass')
            ->and((new Callback('ctx.ok', fn () => Verdict::warn('other', 'hmm')))->grade($run))->toMatchObject(['grader' => 'ctx.ok', 'status' => 'warn'])
            ->and((new Callback('ctx.ok', fn () => 3.5))->grade($run)->status)->toBe('fail');
    });
});

describe('LlmJudge', function () use ($done) {
    it('skips when disabled', function () use ($done) {
        $v = (new LlmJudge('Reply must confirm the course was created.'))->grade(BenchFixtures::run([$done('تم')]));

        expect($v->status)->toBe('skip')->and($v->message)->toContain('disabled');
    });

    it('passes and fails on the faked structured score', function () use ($done) {
        JudgeAgent::fake([
            ['score' => 5, 'reason' => 'Confirms creation with the real name.'],
            ['score' => 2, 'reason' => 'Narrates and never confirms.'],
        ]);

        $run = BenchFixtures::run([$done('تم إنشاء المقرر «الفيزياء».', [['name' => 'upsert_course', 'arguments' => ['name' => 'الفيزياء'], 'result' => '{"id":1}']])]);
        $judge = new LlmJudge('Reply must confirm the course was created.', model: 'judge/model', enabled: true);

        $pass = $judge->grade($run);
        $fail = $judge->grade($run);

        expect($pass->status)->toBe('pass')
            ->and($pass->evidence['score'])->toBe(5)
            ->and($fail->status)->toBe('fail')
            ->and($fail->message)->toContain('2/5')
            ->and($judge->transcript($run))->toContain('USER: prompt 0', 'TOOL upsert_course', 'ASSISTANT [done]: تم إنشاء المقرر');

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'الفيزياء'));
    });

    it('never throws: a provider failure becomes a skip', function () use ($done) {
        JudgeAgent::fake([fn () => throw new RuntimeException('provider down')]);

        $v = (new LlmJudge('rubric', enabled: true))->grade(BenchFixtures::run([$done('تم')]));

        expect($v->status)->toBe('skip')->and($v->message)->toContain('provider down');
    });
});

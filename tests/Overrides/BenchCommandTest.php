<?php

use Illuminate\Contracts\Console\Kernel;
use Saad\AiKit\Bench\BenchRunner;
use Saad\AiKit\Bench\BenchServiceProvider;
use Saad\AiKit\Bench\Console\BenchCommand;
use Saad\AiKit\Bench\TurnDriver;
use Saad\AiKit\Testing\FakeTurnDriver;
use Saad\AiKit\Tests\BenchEnabledTestCase;
use Saad\AiKit\Tests\Support\FakeScenarioProvider;

uses(BenchEnabledTestCase::class);

beforeEach(function () {
    config()->set('ai-kit.bench.providers', [FakeScenarioProvider::class]);
    $this->out = sys_get_temp_dir().'/ai-kit-bench-cmd-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    if (is_dir($this->out)) {
        array_map('unlink', glob("{$this->out}/transcripts/*") ?: []);
        @rmdir("{$this->out}/transcripts");
        array_map('unlink', glob("{$this->out}/*") ?: []);
        @rmdir($this->out);
    }
});

it('registers the module, the command and the runner binding when enabled', function () {
    expect($this->app->providerIsLoaded(BenchServiceProvider::class))->toBeTrue()
        ->and(array_key_exists('ai-kit:bench', $this->app[Kernel::class]->all()))->toBeTrue();
});

it('lists scenarios without running them, honouring --filter and --tag', function () {
    $this->artisan(BenchCommand::class, ['--list' => true])
        ->expectsOutputToContain('structure.create-course')
        ->expectsOutputToContain('grades.lookup')
        ->assertSuccessful();

    $this->artisan(BenchCommand::class, ['--list' => true, '--tag' => ['structure', 'ar']])
        ->expectsOutputToContain('structure.create-course')
        ->doesntExpectOutputToContain('structure.delete-course')
        ->assertSuccessful();

    $this->artisan(BenchCommand::class, ['--list' => true, '--filter' => '/^grades\./'])
        ->expectsOutputToContain('grades.lookup')
        ->doesntExpectOutputToContain('structure.')
        ->assertSuccessful();

    $this->artisan(BenchCommand::class, ['--list' => true, '--filter' => 'nothing-matches'])
        ->expectsOutputToContain('No scenarios matched')
        ->assertFailed();
});

it('fails with a clear message when no TurnDriver is bound', function () {
    $this->artisan(BenchCommand::class, ['--out' => $this->out])
        ->expectsOutputToContain('No Saad\AiKit\Bench\TurnDriver is bound')
        ->assertFailed();
});

it('fails clearly when no providers are configured', function () {
    config()->set('ai-kit.bench.providers', []);

    $this->artisan(BenchCommand::class, ['--list' => true])
        ->expectsOutputToContain('No scenario providers configured')
        ->assertFailed();
});

it('runs against the bound driver, prints a live line per scenario, writes the report, exits by pass@k', function () {
    $driver = (new FakeTurnDriver)->respondWith(function (string $method, array $call) {
        return str_contains($call['prompt'] ?? '', 'أنشئ')
            ? FakeTurnDriver::done('تم إنشاء المقرر.', [['name' => 'upsert_course']], 0.002)
            : FakeTurnDriver::done('Done.', [], 0.001);
    });
    $this->app->instance(TurnDriver::class, $driver);

    expect($this->app->make(BenchRunner::class))->toBeInstanceOf(BenchRunner::class);

    $this->artisan(BenchCommand::class, ['--out' => $this->out, '--attempts' => 2, '--model' => 'x/y'])
        ->expectsOutputToContain('Running 3 scenario(s) × 2 attempt(s) on x/y')
        ->expectsOutputToContain('PASS structure.create-course #1')
        ->expectsOutputToContain('PASS grades.lookup #2')
        ->expectsOutputToContain('Runs passed')
        ->expectsOutputToContain("Report: {$this->out}/report.md")
        ->assertSuccessful();

    expect(file_exists("{$this->out}/report.json"))->toBeTrue()
        ->and(file_exists("{$this->out}/report.md"))->toBeTrue()
        ->and(glob("{$this->out}/transcripts/*.md"))->toHaveCount(6)
        ->and($driver->calls)->toHaveCount(6)
        ->and($driver->calls[0]['model'])->toBe('x/y');

    $json = json_decode(file_get_contents("{$this->out}/report.json"), true);
    expect($json['summary']['totals'])->toMatchArray(['runs' => 6, 'passed_runs' => 6, 'k' => 2]);
});

it('exits non-zero and prints the failing graders when a scenario never passes, and --json prints the summary', function () {
    $this->app->instance(TurnDriver::class, (new FakeTurnDriver)->respondWith(fn () => FakeTurnDriver::done('لم أفعل شيئاً.', [], 0.001)));

    $this->artisan(BenchCommand::class, ['--out' => $this->out, '--json' => true])
        ->expectsOutputToContain('FAIL structure.create-course #1')
        ->expectsOutputToContain('"scenarios_passed_at_k": 2')
        ->assertFailed();

    $json = json_decode(file_get_contents("{$this->out}/report.json"), true);
    expect($json['summary']['grader_failures'])->toBe(['tools_called' => 1]);
});

it('stops on --max-cost and reports the reason', function () {
    $this->app->instance(TurnDriver::class, (new FakeTurnDriver)->respondWith(fn () => FakeTurnDriver::done('ok', [['name' => 'upsert_course']], 0.5)));

    $this->artisan(BenchCommand::class, ['--out' => $this->out, '--max-cost' => '0.6'])
        ->expectsOutputToContain('Budget exhausted')
        ->assertFailed();

    $json = json_decode(file_get_contents("{$this->out}/report.json"), true);
    expect($json['summary']['totals']['runs'])->toBe(2)
        ->and($json['summary']['meta']['stopped_reason'])->toContain('grades.lookup');
});

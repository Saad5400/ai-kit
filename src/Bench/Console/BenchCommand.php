<?php

namespace Saad\AiKit\Bench\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Saad\AiKit\Bench\BenchRunner;
use Saad\AiKit\Bench\ReportWriter;
use Saad\AiKit\Bench\RunOptions;
use Saad\AiKit\Bench\Scenario;
use Saad\AiKit\Bench\ScenarioProvider;
use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;
use Throwable;

class BenchCommand extends Command
{
    protected $signature = 'ai-kit:bench
        {--filter= : Substring (or /regex/) on the scenario name}
        {--tag=* : Only scenarios carrying every given tag}
        {--attempts= : Attempts per scenario (pass@k)}
        {--model= : Override the assistant model for every turn}
        {--out= : Output directory (default storage/ai-kit/bench/<Ymd-His>)}
        {--max-cost= : Stop once the cumulative reported provider cost (USD) exceeds this}
        {--list : Print the scenarios without running them}
        {--json : Print the summary as JSON}';

    protected $description = 'Run the AI assistant scenario benchmark and write a JSON + Markdown report.';

    public function handle(Container $container, ReportWriter $writer): int
    {
        try {
            $scenarios = $this->scenarios($container);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($scenarios === []) {
            $this->components->warn('No scenarios matched. Register ScenarioProvider classes in ai-kit.bench.providers or loosen --filter/--tag.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->table(
                ['Scenario', 'Title', 'Tags', 'Turns', 'Lang'],
                array_map(fn (Scenario $s): array => [$s->name, $s->title, implode(', ', $s->tags), count($s->turns), $s->expectedLanguage ?? '—'], $scenarios),
            );

            return self::SUCCESS;
        }

        try {
            $runner = $container->make(BenchRunner::class);
        } catch (Throwable $e) {
            $this->components->error($e->getPrevious()?->getMessage() ?? $e->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out') ?: $this->defaultOutputDirectory();
        $attempts = (int) ($this->option('attempts') ?: config('ai-kit.bench.default_attempts', 1));
        $maxCost = $this->option('max-cost') !== null ? (float) $this->option('max-cost') : null;

        $this->components->info(sprintf(
            'Running %d scenario(s) × %d attempt(s)%s%s → %s',
            count($scenarios),
            max(1, $attempts),
            $this->option('model') ? ' on '.$this->option('model') : '',
            $maxCost !== null ? sprintf(', budget $%.2f', $maxCost) : '',
            $out,
        ));

        $options = new RunOptions(
            attempts: max(1, $attempts),
            model: $this->option('model') ?: null,
            maxTotalCostUsd: $maxCost,
            onScenarioDone: fn (ScenarioRun $run) => $this->printRun($run),
        );

        $report = $runner->run($scenarios, $options);
        $paths = $writer->write($report, $out);
        $summary = $report->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $totals = $summary['totals'];
            $this->newLine();
            $this->components->twoColumnDetail('Scenarios', (string) $totals['scenarios']);
            $this->components->twoColumnDetail('Runs passed', "{$totals['passed_runs']}/{$totals['runs']}".($totals['pass_rate'] !== null ? sprintf(' (%.0f%%)', $totals['pass_rate'] * 100) : ''));
            $this->components->twoColumnDetail("pass@{$totals['k']}", "{$totals['scenarios_passed_at_k']}/{$totals['scenarios']}");
            $this->components->twoColumnDetail('Cost', sprintf('$%.4f', $totals['cost_usd']));
            $this->components->twoColumnDetail('Wall', sprintf('%.1fs', $totals['wall_ms'] / 1000));

            foreach ($summary['grader_failures'] as $grader => $count) {
                $this->components->twoColumnDetail("  ✗ {$grader}", (string) $count);
            }

            if (! empty($summary['meta']['stopped_reason'])) {
                $this->components->warn($summary['meta']['stopped_reason']);
            }

            $this->components->info("Report: {$paths['markdown']}");
        }

        return $summary['totals']['scenarios_passed_at_k'] === $summary['totals']['scenarios'] && empty($summary['meta']['stopped_reason'])
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return list<Scenario>
     */
    private function scenarios(Container $container): array
    {
        $providers = config('ai-kit.bench.providers', []);

        if ($providers === []) {
            throw new RuntimeException('No scenario providers configured (ai-kit.bench.providers).');
        }

        $scenarios = [];

        foreach ($providers as $class) {
            $provider = $container->make($class);

            if (! $provider instanceof ScenarioProvider) {
                throw new RuntimeException("{$class} is not a ".ScenarioProvider::class.'.');
            }

            foreach ($provider->scenarios() as $scenario) {
                if ($this->matches($scenario)) {
                    $scenarios[] = $scenario;
                }
            }
        }

        return $scenarios;
    }

    private function matches(Scenario $scenario): bool
    {
        $filter = (string) $this->option('filter');

        if ($filter !== '') {
            $isRegex = strlen($filter) > 2 && $filter[0] === '/' && @preg_match($filter, '') !== false;

            if ($isRegex ? ! preg_match($filter, $scenario->name) : ! str_contains($scenario->name, $filter)) {
                return false;
            }
        }

        foreach ((array) $this->option('tag') as $tag) {
            if (! $scenario->hasTag((string) $tag)) {
                return false;
            }
        }

        return true;
    }

    private function printRun(ScenarioRun $run): void
    {
        $failing = array_map(fn (Verdict $v): string => $v->grader, $run->failedVerdicts());

        if ($run->failure !== null) {
            $failing[] = 'crash';
        }

        $this->line(sprintf(
            '  %s %s #%d  $%.4f  %dms%s',
            $run->passed() ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
            $run->scenario->name,
            $run->attempt,
            $run->costUsd,
            $run->wallMs,
            $failing === [] ? '' : '  <fg=yellow>'.implode(', ', array_unique($failing)).'</>',
        ));
    }

    private function defaultOutputDirectory(): string
    {
        $base = (string) config('ai-kit.bench.output_dir', 'ai-kit/bench');

        if (! str_starts_with($base, '/')) {
            $base = storage_path($base);
        }

        return rtrim($base, '/').'/'.date('Ymd-His');
    }
}

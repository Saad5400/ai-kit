<?php

namespace Saad\AiKit\Bench;

/**
 * The bench's output: every {@see ScenarioRun} plus the summary derived
 * from them — totals, pass rate, pass@k per scenario, per-grader failure
 * counts, the slowest and most expensive runs — as arrays, JSON and
 * Markdown. Arabic round-trips (JSON_UNESCAPED_UNICODE).
 */
final class BenchReport
{
    public const TOP_N = 15;

    /**
     * @param  list<ScenarioRun>  $runs
     * @param  array<string, mixed>  $meta
     */
    public function __construct(public array $runs, public array $meta = []) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $scenarios = $this->perScenario();
        $passedRuns = count(array_filter($this->runs, fn (ScenarioRun $run): bool => $run->passed()));
        $passedAtK = count(array_filter($scenarios, fn (array $row): bool => $row['pass_at_k']));
        $totalRuns = count($this->runs);
        $cost = array_sum(array_map(fn (ScenarioRun $run): float => $run->costUsd, $this->runs));
        $wall = array_sum(array_map(fn (ScenarioRun $run): int => $run->wallMs, $this->runs));

        return [
            'totals' => [
                'scenarios' => count($scenarios),
                'runs' => $totalRuns,
                'passed_runs' => $passedRuns,
                'failed_runs' => $totalRuns - $passedRuns,
                'crashed_runs' => count(array_filter($this->runs, fn (ScenarioRun $run): bool => $run->failure !== null)),
                'pass_rate' => $totalRuns === 0 ? null : round($passedRuns / $totalRuns, 4),
                'k' => $this->meta['attempts'] ?? max([1, ...array_map(fn (array $row): int => $row['attempts'], $scenarios)]),
                'scenarios_passed_at_k' => $passedAtK,
                'pass_at_k' => $scenarios === [] ? null : round($passedAtK / count($scenarios), 4),
                'cost_usd' => round($cost, 6),
                'wall_ms' => $wall,
                'warnings' => $this->countStatus(Verdict::WARN),
                'skipped_verdicts' => $this->countStatus(Verdict::SKIP),
            ],
            'scenarios' => array_values($scenarios),
            'grader_failures' => $this->graderFailures(),
            'slowest' => $this->top(fn (ScenarioRun $run): int => $run->wallMs),
            'most_expensive' => $this->top(fn (ScenarioRun $run): float => $run->costUsd),
            'meta' => $this->meta,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function perScenario(): array
    {
        $rows = [];

        foreach ($this->runs as $run) {
            $name = $run->scenario->name;

            $rows[$name] ??= [
                'name' => $name,
                'title' => $run->scenario->title,
                'tags' => $run->scenario->tags,
                'attempts' => 0,
                'passed' => 0,
                'pass_at_k' => false,
                'cost_usd' => 0.0,
                'wall_ms' => 0,
                'failing_graders' => [],
                'warnings' => [],
                'crashes' => [],
            ];

            $rows[$name]['attempts']++;
            $rows[$name]['cost_usd'] = round($rows[$name]['cost_usd'] + $run->costUsd, 6);
            $rows[$name]['wall_ms'] += $run->wallMs;

            if ($run->passed()) {
                $rows[$name]['passed']++;
                $rows[$name]['pass_at_k'] = true;
            }

            foreach ($run->verdicts as $verdict) {
                if ($verdict->failed()) {
                    $rows[$name]['failing_graders'][$verdict->grader] = ($rows[$name]['failing_graders'][$verdict->grader] ?? 0) + 1;
                } elseif ($verdict->status === Verdict::WARN) {
                    $rows[$name]['warnings'][$verdict->grader] = ($rows[$name]['warnings'][$verdict->grader] ?? 0) + 1;
                }
            }

            if ($run->failure !== null) {
                $rows[$name]['crashes'][] = $run->failure;
            }
        }

        foreach ($rows as &$row) {
            $row['avg_cost_usd'] = round($row['cost_usd'] / $row['attempts'], 6);
            $row['avg_wall_ms'] = (int) round($row['wall_ms'] / $row['attempts']);
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function graderFailures(): array
    {
        $counts = [];

        foreach ($this->runs as $run) {
            foreach ($run->failedVerdicts() as $verdict) {
                $counts[$verdict->grader] = ($counts[$verdict->grader] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    public function toJson(): string
    {
        return json_encode([
            'summary' => $this->summary(),
            'runs' => array_map(fn (ScenarioRun $run): array => $run->toArray(), $this->runs),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }

    public function toMarkdown(): string
    {
        $summary = $this->summary();
        $totals = $summary['totals'];
        $lines = [];

        $lines[] = '# AI Bench Report';
        $lines[] = '';
        $lines[] = sprintf(
            '**%d scenarios · %d runs · %s passed (pass rate %s · pass@%d %s) · $%.4f · %s**',
            $totals['scenarios'],
            $totals['runs'],
            $totals['passed_runs'],
            $this->percent($totals['pass_rate']),
            $totals['k'],
            $this->percent($totals['pass_at_k']),
            $totals['cost_usd'],
            $this->duration($totals['wall_ms']),
        );

        if (! empty($this->meta['model'])) {
            $lines[] = '';
            $lines[] = 'Model override: `'.$this->meta['model'].'`';
        }

        if (! empty($this->meta['stopped_reason'])) {
            $lines[] = '';
            $lines[] = '> ⚠ '.$this->meta['stopped_reason'];
        }

        $lines[] = '';
        $lines[] = '## Scenarios';
        $lines[] = '';
        $lines[] = '| Scenario | Tags | Pass | Cost | Wall | Failing graders |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($summary['scenarios'] as $row) {
            $failing = $this->countList($row['failing_graders']);

            if ($row['crashes'] !== []) {
                $failing = trim($failing.' 💥 crash×'.count($row['crashes']));
            }

            $lines[] = sprintf(
                '| %s `%s`<br><small>%s</small> | %s | %d/%d | $%.4f | %s | %s |',
                $row['pass_at_k'] ? '✅' : '❌',
                $row['name'],
                $this->cell($row['title']),
                $this->cell(implode(', ', $row['tags'])),
                $row['passed'],
                $row['attempts'],
                $row['cost_usd'],
                $this->duration($row['wall_ms']),
                $failing === '' ? '—' : $this->cell($failing),
            );
        }

        $lines[] = '';
        $lines[] = '## Failures by grader';
        $lines[] = '';

        if ($summary['grader_failures'] === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Grader | Failures |';
            $lines[] = '|---|---|';

            foreach ($summary['grader_failures'] as $grader => $count) {
                $lines[] = "| `{$grader}` | {$count} |";
            }
        }

        foreach (['slowest' => 'Slowest runs', 'most_expensive' => 'Most expensive runs'] as $key => $title) {
            $lines[] = '';
            $lines[] = "## {$title}";
            $lines[] = '';

            if ($summary[$key] === []) {
                $lines[] = 'None.';

                continue;
            }

            $lines[] = '| Scenario | Attempt | Wall | Cost | Passed |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($summary[$key] as $row) {
                $lines[] = sprintf('| `%s` | %d | %s | $%.4f | %s |', $row['name'], $row['attempt'], $this->duration($row['wall_ms']), $row['cost_usd'], $row['passed'] ? '✅' : '❌');
            }
        }

        $lines[] = '';
        $lines[] = '## Crashes';
        $lines[] = '';
        $crashes = array_filter($this->runs, fn (ScenarioRun $run): bool => $run->failure !== null);

        if ($crashes === []) {
            $lines[] = 'None.';
        } else {
            foreach ($crashes as $run) {
                $lines[] = "- `{$run->scenario->name}` #{$run->attempt}: ".$this->cell($run->failure);
            }
        }

        $lines[] = '';
        $lines[] = '_Transcripts: `transcripts/<scenario>-<attempt>.md`._';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  callable(ScenarioRun): (int|float)  $metric
     * @return list<array{name: string, attempt: int, wall_ms: int, cost_usd: float, passed: bool}>
     */
    private function top(callable $metric): array
    {
        $runs = $this->runs;
        usort($runs, fn (ScenarioRun $a, ScenarioRun $b): int => $metric($b) <=> $metric($a));

        return array_map(fn (ScenarioRun $run): array => [
            'name' => $run->scenario->name,
            'attempt' => $run->attempt,
            'wall_ms' => $run->wallMs,
            'cost_usd' => round($run->costUsd, 6),
            'passed' => $run->passed(),
        ], array_slice($runs, 0, self::TOP_N));
    }

    private function countStatus(string $status): int
    {
        $count = 0;

        foreach ($this->runs as $run) {
            foreach ($run->verdicts as $verdict) {
                if ($verdict->status === $status) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function countList(array $counts): string
    {
        $parts = [];

        foreach ($counts as $name => $count) {
            $parts[] = $count > 1 ? "`{$name}`×{$count}" : "`{$name}`";
        }

        return implode(' ', $parts);
    }

    private function percent(?float $ratio): string
    {
        return $ratio === null ? '—' : sprintf('%.0f%%', $ratio * 100);
    }

    private function duration(int $ms): string
    {
        return $ms >= 1000 ? sprintf('%.1fs', $ms / 1000) : "{$ms}ms";
    }

    private function cell(?string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], (string) $text);
    }
}

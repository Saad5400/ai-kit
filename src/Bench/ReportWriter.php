<?php

namespace Saad\AiKit\Bench;

use Saad\AiKit\Bench\Support\TextScrub;

/**
 * Writes a {@see BenchReport} to disk: `report.json`, `report.md` and one
 * full transcript per run under `transcripts/<scenario>-<attempt>.md`.
 */
final class ReportWriter
{
    public const RESULT_EXCERPT = 600;

    /**
     * @return array{json: string, markdown: string, transcripts: list<string>}
     */
    public function write(BenchReport $report, string $directory): array
    {
        $directory = rtrim($directory, '/');
        $transcripts = $directory.'/transcripts';

        if (! is_dir($transcripts) && ! mkdir($transcripts, 0775, true) && ! is_dir($transcripts)) {
            throw new \RuntimeException("Could not create {$transcripts}.");
        }

        $paths = [
            'json' => $directory.'/report.json',
            'markdown' => $directory.'/report.md',
            'transcripts' => [],
        ];

        file_put_contents($paths['json'], $report->toJson());
        file_put_contents($paths['markdown'], $report->toMarkdown());

        foreach ($report->runs as $run) {
            $path = $transcripts.'/'.$this->slug($run->scenario->name).'-'.$run->attempt.'.md';
            file_put_contents($path, $this->transcript($run));
            $paths['transcripts'][] = $path;
        }

        return $paths;
    }

    public function transcript(ScenarioRun $run): string
    {
        $scenario = $run->scenario;
        $lines = [];

        $lines[] = "# {$scenario->title}";
        $lines[] = '';
        $lines[] = "`{$scenario->name}` · attempt {$run->attempt} · ".($run->passed() ? '✅ passed' : '❌ failed')
            .sprintf(' · $%.4f · %dms', $run->costUsd, $run->wallMs);

        if ($scenario->tags !== []) {
            $lines[] = 'Tags: '.implode(', ', $scenario->tags);
        }

        if ($scenario->description !== null) {
            $lines[] = '';
            $lines[] = $scenario->description;
        }

        if ($run->failure !== null) {
            $lines[] = '';
            $lines[] = '## 💥 Crash';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $run->failure;
            $lines[] = '```';
        }

        $boundaries = array_flip($run->turnBoundaries);

        foreach ($run->turns as $index => $record) {
            $lines[] = '';

            if (isset($boundaries[$index])) {
                $turnNo = $boundaries[$index] + 1;
                $turn = $scenario->turns[$boundaries[$index]] ?? null;
                $lines[] = "## Turn {$turnNo}";
                $lines[] = '';
                $lines[] = '**User:**';
                $lines[] = '';
                $lines[] = $this->quote($turn?->prompt ?? '');

                if ($turn !== null && $turn->files !== []) {
                    $lines[] = '';
                    $lines[] = 'Files: '.implode(', ', array_map(fn (array $f): string => "`{$f['name']}` ({$f['mime']})", $turn->files));
                }
            } else {
                $lines[] = '### ↩ Resumed (decisions sent)';
            }

            $lines[] = '';
            $lines[] = sprintf(
                '_%s · turn `%s` · %dms · $%s · %d invocation(s), %d+%d tokens_',
                $record->status,
                $record->turnId,
                $record->wallMs,
                $record->costUsd() === null ? '—' : sprintf('%.4f', $record->costUsd()),
                $record->usage['invocations'] ?? 0,
                $record->usage['prompt_tokens'] ?? 0,
                $record->usage['completion_tokens'] ?? 0,
            );

            foreach ($record->toolCalls as $call) {
                $lines[] = '';
                $flag = ($call['error'] ?? false) ? ' ⚠' : '';
                $lines[] = "**🔧 {$call['name']}**{$flag} `{$call['status']}`";

                if ($call['arguments'] !== null) {
                    $lines[] = '';
                    $lines[] = '```json';
                    $lines[] = json_encode($call['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    $lines[] = '```';
                }

                if ($call['result'] !== null && $call['result'] !== '') {
                    $lines[] = '';
                    $lines[] = '```';
                    $lines[] = TextScrub::excerpt($call['result'], self::RESULT_EXCERPT);
                    $lines[] = '```';
                }
            }

            foreach ($record->pendingCards as $card) {
                $lines[] = '';
                $lines[] = "**🃏 {$card['kind']} card** `{$card['id']}`: ".($card['title'] ?? '');

                if (! empty($card['options'])) {
                    $lines[] = 'Options: '.json_encode($card['options'], JSON_UNESCAPED_UNICODE);
                }

                if (! empty($card['fields'])) {
                    $lines[] = 'Fields: '.json_encode($card['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $lines[] = '';
            $lines[] = '**Assistant:**';
            $lines[] = '';
            $lines[] = $record->text !== '' ? $this->quote($record->text) : '_(no text)_';

            if ($record->error !== null) {
                $lines[] = '';
                $lines[] = '**Error:** '.$record->error;
            }

            if ($record->meta !== []) {
                $lines[] = '';
                $lines[] = 'Meta: `'.json_encode($record->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'`';
            }
        }

        $lines[] = '';
        $lines[] = '## Verdicts';
        $lines[] = '';

        if ($run->verdicts === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Grader | Status | Message |';
            $lines[] = '|---|---|---|';

            foreach ($run->verdicts as $verdict) {
                $icon = match ($verdict->status) {
                    Verdict::PASS => '✅',
                    Verdict::FAIL => '❌',
                    Verdict::WARN => '⚠️',
                    default => '⏭',
                };
                $lines[] = sprintf('| `%s` | %s %s | %s |', $verdict->grader, $icon, $verdict->status, str_replace(['|', "\n"], ['\\|', ' '], $verdict->message));
            }

            $withEvidence = array_filter($run->verdicts, fn (Verdict $v): bool => $v->evidence !== [] && $v->status !== Verdict::PASS);

            if ($withEvidence !== []) {
                $lines[] = '';
                $lines[] = '<details><summary>Evidence</summary>';
                $lines[] = '';
                $lines[] = '```json';
                $lines[] = json_encode(
                    array_values(array_map(fn (Verdict $v): array => ['grader' => $v->grader, 'evidence' => $v->evidence], $withEvidence)),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                );
                $lines[] = '```';
                $lines[] = '';
                $lines[] = '</details>';
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    public function slug(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9._-]+/i', '-', $name) ?? $name;

        return trim($slug, '-') ?: 'scenario';
    }

    private function quote(string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => '> '.$line, explode("\n", $text)));
    }
}

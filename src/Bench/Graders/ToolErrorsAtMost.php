<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\TextScrub;
use Saad\AiKit\Bench\Verdict;

/**
 * Counts tool calls whose result was flagged `error` or whose result text
 * carries an error marker (configurable: `ai-kit.bench.tool_error_markers`).
 */
final class ToolErrorsAtMost extends AbstractGrader
{
    /**
     * @param  list<string>|null  $markers  case-insensitive substrings; null reads config
     */
    public function __construct(private int $max = 0, private ?array $markers = null, private bool $flagOnly = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $markers = $this->flagOnly ? [] : ($this->markers ?? $this->config('tool_error_markers', ['error', 'not permitted', 'failed']));
        $errors = [];

        foreach ($run->toolCalls() as $call) {
            $result = (string) ($call['result'] ?? '');
            $matched = (bool) ($call['error'] ?? false);

            foreach ($markers as $marker) {
                if ($result !== '' && mb_stripos($result, $marker) !== false) {
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                $errors[] = ['tool' => $call['name'], 'excerpt' => TextScrub::excerpt($result)];
            }
        }

        return count($errors) <= $this->max
            ? $this->pass(count($errors)." tool error(s) ≤ {$this->max}")
            : $this->fail(count($errors)." tool error(s) > {$this->max}", ['errors' => $errors]);
    }
}

<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\TextScrub;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;

/**
 * Fails when a tool result carries laravel/ai's step-limit sentinel, or a
 * turn finished `done` after calling tools but with no trailing text —
 * the model ran out of steps before it could answer.
 */
final class NoStepExhaustion extends AbstractGrader
{
    /**
     * @param  list<string>|null  $markers  null reads `ai-kit.bench.step_exhaustion_markers`
     */
    public function __construct(private ?array $markers = null) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $markers = $this->markers ?? $this->config('step_exhaustion_markers', ['maximum number of steps']);
        $findings = [];

        foreach ($run->turns as $index => $record) {
            foreach ($record->toolCalls as $call) {
                $result = (string) ($call['result'] ?? '');

                foreach ($markers as $marker) {
                    if ($result !== '' && stripos($result, $marker) !== false) {
                        $findings[] = ['turn' => $index, 'tool' => $call['name'], 'marker' => $marker, 'excerpt' => TextScrub::excerpt($result)];
                    }
                }
            }

            if ($record->status === TurnRecord::STATUS_DONE && $record->toolCalls !== [] && trim($record->text) === '') {
                $findings[] = ['turn' => $index, 'tools' => $record->toolNames(), 'reason' => 'done with tool calls but blank trailing text'];
            }
        }

        return $findings === []
            ? $this->pass()
            : $this->fail('Step exhaustion detected in '.count($findings).' place(s).', ['findings' => $findings]);
    }
}

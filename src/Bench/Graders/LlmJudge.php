<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\TextScrub;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;
use Throwable;

/**
 * OPTIONAL rubric grading by a model. Off unless `ai-kit.bench.judge.enabled`
 * (or the constructor) says so — it spends real money. Scores 1–5 with a
 * strict schema and passes at `passAt`. It never throws the run: any
 * provider or parsing failure is a `skip` verdict with the reason.
 */
final class LlmJudge extends AbstractGrader
{
    public function __construct(
        private string $rubric,
        private ?string $model = null,
        private int $passAt = 4,
        private ?bool $enabled = null,
        private ?string $provider = null,
        private ?string $judgeName = null,
    ) {}

    public function name(): string
    {
        return $this->judgeName ?? 'llm_judge';
    }

    public function grade(ScenarioRun $run): Verdict
    {
        $enabled = $this->enabled ?? (bool) $this->config('judge.enabled', false);

        if (! $enabled) {
            return $this->skip('LLM judge disabled (ai-kit.bench.judge.enabled).');
        }

        $passAt = $this->passAt ?: (int) $this->config('judge.pass_at', 4);
        $model = $this->model ?? $this->config('judge.model') ?? (function_exists('config') ? config('ai-kit.chat.model') : null);
        $provider = $this->provider ?? $this->config('judge.provider', 'openrouter');

        try {
            $response = (new JudgeAgent($this->rubric))->prompt($this->transcript($run), provider: $provider, model: $model);
            $structured = method_exists($response, 'toArray') ? $response->toArray() : [];
            $score = (int) ($structured['score'] ?? 0);
            $reason = (string) ($structured['reason'] ?? '');
        } catch (Throwable $e) {
            return $this->skip('Judge unavailable: '.$e::class.': '.$e->getMessage());
        }

        if ($score < 1 || $score > 5) {
            return $this->skip('Judge returned no valid score.', ['raw' => $structured]);
        }

        $evidence = ['score' => $score, 'pass_at' => $passAt, 'reason' => $reason, 'model' => $model];

        return $score >= $passAt
            ? $this->pass("Score {$score}/5: ".TextScrub::excerpt($reason, 200), $evidence)
            : $this->fail("Score {$score}/5 (< {$passAt}): ".TextScrub::excerpt($reason, 200), $evidence);
    }

    /** A compact, model-facing transcript: prompts, tool names, texts, cards. */
    public function transcript(ScenarioRun $run): string
    {
        $lines = ["Scenario: {$run->scenario->title}"];

        if ($run->scenario->expectedLanguage !== null) {
            $lines[] = "Expected language: {$run->scenario->expectedLanguage}";
        }

        $boundaries = array_flip($run->turnBoundaries);

        foreach ($run->turns as $index => $record) {
            if (isset($boundaries[$index])) {
                $prompt = $run->scenario->turns[$boundaries[$index]]->prompt ?? '';
                $lines[] = "\nUSER: {$prompt}";
            } else {
                $lines[] = "\n(user resumed the paused turn)";
            }

            foreach ($record->toolCalls as $call) {
                $args = $call['arguments'] === null ? '' : ' '.json_encode($call['arguments'], JSON_UNESCAPED_UNICODE);
                $lines[] = "TOOL {$call['name']}{$args} → ".TextScrub::excerpt($call['result'], 200);
            }

            foreach ($record->pendingCards as $card) {
                $lines[] = "CARD [{$card['kind']}] ".($card['title'] ?? '');
            }

            $lines[] = 'ASSISTANT ['.$record->status.']: '.($record->text !== '' ? $record->text : '(no text)');

            if ($record->status === TurnRecord::STATUS_ERROR) {
                $lines[] = 'ERROR: '.$record->error;
            }
        }

        return implode("\n", $lines);
    }
}

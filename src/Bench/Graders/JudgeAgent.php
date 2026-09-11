<?php

namespace Saad\AiKit\Bench\Graders;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The tool-less structured agent behind {@see LlmJudge}: reads a rubric +
 * transcript and returns a 1–5 score with a one-paragraph reason. Kept a
 * named class so tests can `JudgeAgent::fake([...])` it.
 */
final class JudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private string $rubric) {}

    public function instructions(): string
    {
        return <<<TXT
        You are a strict evaluator of an AI assistant's conversation with a user. Judge ONLY against the rubric below.
        Score 5 = fully meets the rubric with no defects; 4 = meets it with minor cosmetic issues; 3 = partially; 2 = mostly fails; 1 = fails or harmful.
        Quote concrete evidence from the transcript in the reason. Never reward narration, placeholders, leaked markup, or the wrong language.

        RUBRIC:
        {$this->rubric}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->min(1)->max(5)->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}

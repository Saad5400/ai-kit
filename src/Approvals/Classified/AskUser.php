<?php

namespace Saad\AiKit\Approvals\Classified;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Mid-turn questions on the SAME paused-turn contract as approvals (owner
 * decision #6): the model calls this tool, the turn pauses exactly like a
 * destructive write would, the client renders a question card instead of an
 * approval card, and the user's answer resumes the turn as an edit decision:
 *
 *     ResumeDecisions::fromClient([$id => ['action' => 'edit',
 *         'arguments' => ['question' => ..., 'answer' => $text]]])
 *
 * Because the answer arrives through `Decision::edit`, dismissing the card
 * (reject) degrades gracefully — the model reads a denial result and moves
 * on without the information.
 *
 * Register it on an agent like any other tool. The model cannot answer for
 * the user: the pause is unconditional, so an `answer` argument the model
 * fabricates is discarded with the rest of the pre-pause arguments only if
 * the user edits — apps SHOULD always resume questions with an edit, never
 * a bare approve.
 */
class AskUser implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Ask the user one clarifying question and wait for their answer. '
            .'Use it when the request cannot be completed correctly without information only the user has. '
            .'Ask at most one focused question at a time.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The question to show the user, in the conversation language.')
                ->required(),
        ];
    }

    /**
     * Always pause — the question IS the reason, so generic card renderers
     * have something to show even without the kit presenter.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $question = trim((string) ($request['question'] ?? ''));

        return Approval::required($question === '' ? null : $question);
    }

    public function handle(Request $request): Stringable|string
    {
        $answer = trim((string) ($request['answer'] ?? ''));

        return $answer === ''
            ? 'The user did not provide an answer. Continue without this information and say what you assumed.'
            : 'The user answered: '.$answer;
    }

    public function name(): string
    {
        return class_basename(static::class);
    }
}

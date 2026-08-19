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
 * The model may propose 2–{@see MAX_OPTIONS} suggested answers in `options`;
 * the question card carries them as one-tap chips next to a free-text input
 * (the Claude-style shape). Options are suggestions only — the answer that
 * resumes the turn is whatever the user sent, chip or typed.
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

    /**
     * How many suggested answers survive to the card. The schema asks for
     * 2–4; the cap is enforced server-side so a model that ignores the
     * instruction cannot turn the card into a wall of chips.
     */
    public const MAX_OPTIONS = 4;

    public function description(): Stringable|string
    {
        return 'Ask the user one clarifying question and wait for their answer. '
            .'Use it when the request cannot be completed correctly without information only the user has. '
            .'Ask at most one focused question at a time. '
            .'When the useful answers are a short enumerable set, pass them as `options` so the user can '
            .'pick one with a single tap; omit `options` entirely for genuinely open questions, and never '
            .'invent options just to fill the field — the user can always type an answer instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The question to show the user, in the conversation language.')
                ->required(),
            'options' => $schema->array()
                ->items($schema->string())
                ->min(2)
                ->max(self::MAX_OPTIONS)
                ->unique()
                ->description(
                    'Optional: 2 to '.self::MAX_OPTIONS.' suggested answers, in the conversation language, '
                    .'shown as one-tap choices. Omit for open questions.'
                ),
        ];
    }

    /**
     * The model's suggested answers, sanitized: strings only, trimmed,
     * de-duplicated, empties dropped, capped at {@see MAX_OPTIONS}.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    public static function options(array $arguments): array
    {
        $options = $arguments['options'] ?? null;

        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(fn (mixed $option): bool => is_string($option) || is_numeric($option))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter(fn (string $option): bool => $option !== '')
            ->unique()
            ->take(self::MAX_OPTIONS)
            ->values()
            ->all();
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

    /**
     * The one editable input on a question card is the answer. Declaring it
     * here is what lets {@see ApprovalCards::guardEdits()} treat a question
     * resume like any other edit: the answer passes through, the model's own
     * `question` / `options` are restored from the pause rather than taken
     * from the client.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return [
            'question' => Field::readonly('question'),
            'options' => Field::hidden('options'),
            'answer' => Field::make('answer', FieldWidget::Textarea),
        ];
    }

    public function name(): string
    {
        return class_basename(static::class);
    }
}

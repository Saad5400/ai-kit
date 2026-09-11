<?php

namespace Saad\AiKit\Bench;

use Closure;

/**
 * How the runner answers the cards a turn pauses on, auto-resuming the
 * SAME turn through {@see TurnDriver::decide()}. `none` leaves the turn
 * paused so graders can inspect the cards; `custom` hands the paused
 * record to a closure that returns the decisions map itself.
 */
final class DecisionPolicy
{
    public const APPROVE_ALL = 'approve_all';

    public const REJECT_ALL = 'reject_all';

    public const ANSWER = 'answer';

    public const NONE = 'none';

    public const CUSTOM = 'custom';

    /** Guard against infinite ask loops: at most this many decide() rounds per turn. */
    public int $maxRounds = 3;

    /** Default answer once scripted answers run out (bilingual, model-facing). */
    public string $fallbackAnswer = 'استمر بأفضل تقدير. Go ahead with your best judgement.';

    /**
     * @param  list<string>  $answers
     * @param  (Closure(TurnRecord): array<string, array{approve?: bool, answer?: string}>)|null  $decide
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $answers = [],
        public readonly ?Closure $decide = null,
    ) {}

    public static function approveAll(): self
    {
        return new self(self::APPROVE_ALL);
    }

    public static function rejectAll(): self
    {
        return new self(self::REJECT_ALL);
    }

    /**
     * AskUser answers in order (a string or a list of strings); approval
     * cards raised along the way are approved.
     *
     * @param  string|list<string>  $answers
     */
    public static function answer(string|array $answers): self
    {
        return new self(self::ANSWER, array_values((array) $answers));
    }

    /** Leave the turn paused — the scenario ends there; graders inspect pendingCards. */
    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * @param  Closure(TurnRecord): array<string, array{approve?: bool, answer?: string}>  $decide
     */
    public static function custom(Closure $decide): self
    {
        return new self(self::CUSTOM, decide: $decide);
    }

    public function withMaxRounds(int $rounds): self
    {
        $this->maxRounds = max(0, $rounds);

        return $this;
    }

    public function withFallbackAnswer(string $answer): self
    {
        $this->fallbackAnswer = $answer;

        return $this;
    }

    public function resumes(): bool
    {
        return $this->kind !== self::NONE;
    }

    /**
     * Build the decisions map for a paused record. $answerCursor advances
     * through the scripted answers across rounds of the same turn.
     *
     * @return array<string, array{approve?: bool, answer?: string}>
     */
    public function decisionsFor(TurnRecord $paused, int &$answerCursor): array
    {
        if ($this->kind === self::CUSTOM) {
            return ($this->decide)($paused);
        }

        $decisions = [];

        foreach ($paused->pendingCards as $card) {
            $id = (string) ($card['id'] ?? '');

            if ($id === '') {
                continue;
            }

            if (($card['kind'] ?? TurnRecord::CARD_APPROVAL) === TurnRecord::CARD_QUESTION) {
                $decisions[$id] = ['answer' => $this->answers[$answerCursor] ?? $this->fallbackAnswer];
                $answerCursor++;

                continue;
            }

            $decisions[$id] = ['approve' => $this->kind !== self::REJECT_ALL];
        }

        return $decisions;
    }
}

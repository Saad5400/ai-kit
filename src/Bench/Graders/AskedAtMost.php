<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;

/** Caps the number of clarifying-question cards raised across the run. */
final class AskedAtMost extends AbstractGrader
{
    public function __construct(private int $max) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $questions = $run->cards(TurnRecord::CARD_QUESTION);
        $count = count($questions);

        return $count <= $this->max
            ? $this->pass("{$count} question(s) ≤ {$this->max}")
            : $this->fail("{$count} question(s) > {$this->max}", ['questions' => array_column($questions, 'title')]);
    }
}

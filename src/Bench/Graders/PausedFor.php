<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;

/** The run must have raised at least $min cards of the given kind (approval|question). */
final class PausedFor extends AbstractGrader
{
    public function __construct(private string $kind = TurnRecord::CARD_APPROVAL, private int $min = 1) {}

    public function name(): string
    {
        return 'paused_for_'.$this->kind;
    }

    public function grade(ScenarioRun $run): Verdict
    {
        $cards = $run->cards($this->kind);
        $count = count($cards);

        return $count >= $this->min
            ? $this->pass("{$count} {$this->kind} card(s)", ['titles' => array_column($cards, 'title')])
            : $this->fail("Expected ≥ {$this->min} {$this->kind} card(s), got {$count}.", ['all_cards' => $run->cards()]);
    }
}

<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** The last record's text is non-blank — unless the run ended paused on a card, which is a valid ending. */
final class ReplyNotEmpty extends AbstractGrader
{
    public function grade(ScenarioRun $run): Verdict
    {
        $last = $run->lastTurn();

        if ($last === null) {
            return $this->fail('No turn was recorded.');
        }

        if ($last->isPaused() && $last->pendingCards !== []) {
            return $this->pass('Ended on a card ('.count($last->pendingCards).').');
        }

        if (trim($last->text) === '') {
            return $this->fail('Final text is blank.', ['status' => $last->status, 'error' => $last->error]);
        }

        return $this->pass();
    }
}

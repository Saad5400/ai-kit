<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Reported provider cost of the whole run stays under the cap (USD). */
final class CostUnder extends AbstractGrader
{
    public function __construct(private float $maxUsd) {}

    public function grade(ScenarioRun $run): Verdict
    {
        return $run->costUsd <= $this->maxUsd
            ? $this->pass(sprintf('$%.4f ≤ $%.4f', $run->costUsd, $this->maxUsd))
            : $this->fail(sprintf('$%.4f > $%.4f', $run->costUsd, $this->maxUsd), ['cost_usd' => $run->costUsd]);
    }
}

<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Summed driver wall time of the run stays under the cap (ms). */
final class WallUnder extends AbstractGrader
{
    public function __construct(private int $maxMs) {}

    public function grade(ScenarioRun $run): Verdict
    {
        return $run->wallMs <= $this->maxMs
            ? $this->pass("{$run->wallMs}ms ≤ {$this->maxMs}ms")
            : $this->fail("{$run->wallMs}ms > {$this->maxMs}ms", ['wall_ms' => $run->wallMs]);
    }
}

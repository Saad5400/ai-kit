<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

final class ToolCallsAtMost extends AbstractGrader
{
    public function __construct(private int $max) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $count = count($run->toolCalls());

        return $count <= $this->max
            ? $this->pass("{$count} tool call(s) ≤ {$this->max}")
            : $this->fail("{$count} tool call(s) > {$this->max}", ['tools' => $run->toolNames()]);
    }
}

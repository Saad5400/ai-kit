<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\ToolName;
use Saad\AiKit\Bench\Verdict;

/**
 * Compares the tools called across the run against expectations. Names
 * on both sides are normalized (`UpsertCourse` == `upsert_course`).
 */
final class ToolsCalled extends AbstractGrader
{
    /**
     * @param  list<string>  $any  at least one of these must have been called
     * @param  list<string>  $all  every one of these must have been called
     * @param  list<string>  $none  none of these may have been called
     * @param  list<string>  $inOrder  these must appear in this relative order (other calls may interleave)
     */
    public function __construct(
        private array $any = [],
        private array $all = [],
        private array $none = [],
        private array $inOrder = [],
    ) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $called = ToolName::normalizeAll($run->toolNames());
        $set = array_flip($called);
        $problems = [];

        $any = ToolName::normalizeAll($this->any);
        if ($any !== [] && array_intersect($any, $called) === []) {
            $problems[] = 'none of ['.implode(', ', $any).'] was called';
        }

        $missing = array_values(array_diff(ToolName::normalizeAll($this->all), $called));
        if ($missing !== []) {
            $problems[] = 'missing ['.implode(', ', $missing).']';
        }

        $forbidden = array_values(array_filter(ToolName::normalizeAll($this->none), fn (string $n): bool => isset($set[$n])));
        if ($forbidden !== []) {
            $problems[] = 'forbidden ['.implode(', ', $forbidden).'] was called';
        }

        if ($this->inOrder !== [] && ! $this->inOrder($called, ToolName::normalizeAll($this->inOrder))) {
            $problems[] = 'expected order ['.implode(' → ', ToolName::normalizeAll($this->inOrder)).'] not respected';
        }

        $evidence = ['called' => $called];

        return $problems === []
            ? $this->pass('Called: '.implode(', ', $called), $evidence)
            : $this->fail(implode('; ', $problems), $evidence);
    }

    /**
     * @param  list<string>  $called
     * @param  list<string>  $expected
     */
    private function inOrder(array $called, array $expected): bool
    {
        $cursor = 0;

        foreach ($called as $name) {
            if ($cursor < count($expected) && $name === $expected[$cursor]) {
                $cursor++;
            }
        }

        return $cursor === count($expected);
    }
}

<?php

namespace Saad\AiKit\Bench\Graders;

use Closure;
use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/**
 * An arbitrary app assertion. The closure returns a Verdict, a bool
 * (pass/fail), or a string (a failure message; '' passes).
 */
final class Callback extends AbstractGrader
{
    /**
     * @param  Closure(ScenarioRun): (Verdict|bool|string|null)  $fn
     */
    public function __construct(private string $graderName, private Closure $fn) {}

    public function name(): string
    {
        return $this->graderName;
    }

    public function grade(ScenarioRun $run): Verdict
    {
        $result = ($this->fn)($run);

        return match (true) {
            $result instanceof Verdict => $result->grader === $this->graderName ? $result : new Verdict($this->graderName, $result->status, $result->message, $result->evidence),
            $result === true, $result === null, $result === '' => $this->pass(),
            $result === false => $this->fail('Callback returned false.'),
            is_string($result) => $this->fail($result),
            default => $this->fail('Callback returned an unexpected '.get_debug_type($result).'.'),
        };
    }
}

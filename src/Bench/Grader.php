<?php

namespace Saad\AiKit\Bench;

/**
 * Judges one finished (or crashed) scenario run. Graders must be
 * deterministic unless they say otherwise (the LLM judge); an exception
 * thrown here becomes a failed verdict, never a crashed bench.
 */
interface Grader
{
    public function name(): string;

    public function grade(ScenarioRun $run): Verdict;
}

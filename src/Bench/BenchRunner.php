<?php

namespace Saad\AiKit\Bench;

/**
 * Orchestrates the bench: for each scenario × attempt, seed → run the
 * turns (auto-resuming pauses per the turn's DecisionPolicy) → grade →
 * teardown. Full implementation lands in the next commit.
 */
final class BenchRunner
{
    public function __construct(private TurnDriver $driver) {}

    /**
     * @param  iterable<Scenario>  $scenarios
     */
    public function run(iterable $scenarios, RunOptions $options): BenchReport
    {
        return new BenchReport([]);
    }
}

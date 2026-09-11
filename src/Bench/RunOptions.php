<?php

namespace Saad\AiKit\Bench;

use Closure;

final class RunOptions
{
    /**
     * @param  (Closure(ScenarioRun): void)|null  $onScenarioDone  called after each attempt is graded
     * @param  (Closure(Scenario, int): void)|null  $beforeScenario  e.g. begin a DB transaction
     * @param  (Closure(Scenario, int, ScenarioRun): void)|null  $afterScenario  e.g. roll it back
     */
    public function __construct(
        public int $attempts = 1,
        public ?string $model = null,
        public ?float $maxTotalCostUsd = null,
        public ?Closure $onScenarioDone = null,
        public ?Closure $beforeScenario = null,
        public ?Closure $afterScenario = null,
        public bool $stopOnBudget = true,
    ) {}
}

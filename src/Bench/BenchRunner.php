<?php

namespace Saad\AiKit\Bench;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the bench: for each scenario × attempt, seed → run the
 * turns (auto-resuming pauses per the turn's DecisionPolicy) → grade →
 * teardown. A crash anywhere inside one attempt is recorded on that
 * {@see ScenarioRun} and never aborts the bench; a grader exception is a
 * failed verdict. The cumulative budget is summed from the records'
 * `usage.cost_usd` — the app's reported provider cost, never an estimate.
 */
final class BenchRunner
{
    public const BUILTIN_COST_GRADER = 'scenario.max_cost';

    public const BUILTIN_WALL_GRADER = 'scenario.max_wall';

    public function __construct(private TurnDriver $driver) {}

    /**
     * @param  iterable<Scenario>  $scenarios
     */
    public function run(iterable $scenarios, RunOptions $options): BenchReport
    {
        $runs = [];
        $meta = [
            'started_at' => date(DATE_ATOM),
            'model' => $options->model,
            'attempts' => max(1, $options->attempts),
            'max_total_cost_usd' => $options->maxTotalCostUsd,
            'stopped_reason' => null,
        ];
        $spent = 0.0;
        $started = hrtime(true);

        foreach ($scenarios as $scenario) {
            for ($attempt = 1; $attempt <= max(1, $options->attempts); $attempt++) {
                if ($this->overBudget($spent, $options)) {
                    $meta['stopped_reason'] = sprintf(
                        'Budget exhausted: spent $%.4f of the $%.4f cap before %s #%d.',
                        $spent,
                        $options->maxTotalCostUsd,
                        $scenario->name,
                        $attempt,
                    );

                    break 2;
                }

                $run = $this->runAttempt($scenario, $attempt, $options, $spent);
                $runs[] = $run;
                $spent += $run->costUsd;

                if ($options->onScenarioDone !== null) {
                    ($options->onScenarioDone)($run);
                }
            }
        }

        $meta['finished_at'] = date(DATE_ATOM);
        $meta['wall_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
        $meta['cost_usd'] = round($spent, 6);

        return new BenchReport($runs, $meta);
    }

    private function runAttempt(Scenario $scenario, int $attempt, RunOptions $options, float $spentBefore): ScenarioRun
    {
        $run = new ScenarioRun($scenario, $attempt);
        $started = hrtime(true);

        try {
            if ($options->beforeScenario !== null) {
                ($options->beforeScenario)($scenario, $attempt);
            }

            $run->context = $scenario->seed !== null ? ($scenario->seed)() : null;
            $actor = $this->resolveActor($scenario, $run->context);

            $this->runTurns($run, $actor, $options, $spentBefore);
        } catch (Throwable $e) {
            $run->failure = $this->describe($e);
        }

        if ($run->failure === null) {
            $this->grade($run, $scenario->graders);
            $this->gradeBuiltins($run);
        }

        try {
            if ($scenario->teardown !== null) {
                ($scenario->teardown)($run->context);
            }
        } catch (Throwable $e) {
            $run->failure = trim(($run->failure ?? '')."\nteardown: ".$this->describe($e));
        }

        try {
            if ($options->afterScenario !== null) {
                ($options->afterScenario)($scenario, $attempt, $run);
            }
        } catch (Throwable $e) {
            $run->failure = trim(($run->failure ?? '')."\nafterScenario: ".$this->describe($e));
        }

        // Wall is the driver-reported sum; keep the orchestration overhead
        // separately visible for the report without polluting the turns.
        $run->wallMs = max($run->wallMs, (int) ((hrtime(true) - $started) / 1_000_000));

        return $run;
    }

    private function runTurns(ScenarioRun $run, Authenticatable $actor, RunOptions $options, float $spentBefore): void
    {
        $conversationId = null;

        foreach ($run->scenario->turns as $index => $turn) {
            $run->turnBoundaries[] = count($run->turns);

            $record = $this->driver->run($actor, $turn->prompt, $turn->files, $conversationId, $options->model);
            $run->addTurn($record);
            $conversationId = $record->conversationId ?? $conversationId;

            $policy = $turn->onPause ?? DecisionPolicy::approveAll();
            $rounds = 0;
            $cursor = 0;

            while ($record->isPaused() && $policy->resumes() && $rounds < $policy->maxRounds) {
                if ($conversationId === null) {
                    throw new RuntimeException("Turn #{$index} paused without a conversation id; the driver must return one to resume.");
                }

                $decisions = $policy->decisionsFor($record, $cursor);

                if ($decisions === []) {
                    break;
                }

                $record = $this->driver->decide($actor, $conversationId, $decisions, $options->model);
                $run->addTurn($record);
                $conversationId = $record->conversationId ?? $conversationId;
                $rounds++;
            }

            $this->grade($run, $turn->graders);

            if ($this->overBudget($spentBefore + $run->costUsd, $options)) {
                $run->verdicts[] = Verdict::fail(
                    'bench.budget',
                    sprintf('Budget cap $%.4f exceeded mid-scenario; remaining turns skipped.', $options->maxTotalCostUsd),
                );

                return;
            }

            // A turn that errored, was cancelled, or is still paused (policy
            // `none`) ends the story: later prompts would build on nothing.
            if (! $record->isDone()) {
                return;
            }
        }
    }

    private function resolveActor(Scenario $scenario, mixed $context): Authenticatable
    {
        if ($scenario->actor === null) {
            throw new RuntimeException("Scenario {$scenario->name} defines no actor closure.");
        }

        $actor = ($scenario->actor)($context);

        if (! $actor instanceof Authenticatable) {
            throw new RuntimeException("Scenario {$scenario->name}: actor closure must return an Authenticatable.");
        }

        return $actor;
    }

    /**
     * @param  list<Grader>  $graders
     */
    private function grade(ScenarioRun $run, array $graders): void
    {
        foreach ($graders as $grader) {
            try {
                $run->verdicts[] = $grader->grade($run);
            } catch (Throwable $e) {
                $run->verdicts[] = Verdict::fail($grader->name(), 'Grader threw: '.$this->describe($e));
            }
        }
    }

    private function gradeBuiltins(ScenarioRun $run): void
    {
        $scenario = $run->scenario;

        if ($scenario->maxCostUsd !== null) {
            $run->verdicts[] = $run->costUsd <= $scenario->maxCostUsd
                ? Verdict::pass(self::BUILTIN_COST_GRADER, sprintf('$%.4f ≤ $%.4f', $run->costUsd, $scenario->maxCostUsd))
                : Verdict::fail(self::BUILTIN_COST_GRADER, sprintf('$%.4f > $%.4f', $run->costUsd, $scenario->maxCostUsd), ['cost_usd' => $run->costUsd]);
        }

        if ($scenario->maxWallMs !== null) {
            $run->verdicts[] = $run->wallMs <= $scenario->maxWallMs
                ? Verdict::pass(self::BUILTIN_WALL_GRADER, "{$run->wallMs}ms ≤ {$scenario->maxWallMs}ms")
                : Verdict::fail(self::BUILTIN_WALL_GRADER, "{$run->wallMs}ms > {$scenario->maxWallMs}ms", ['wall_ms' => $run->wallMs]);
        }
    }

    private function overBudget(float $spent, RunOptions $options): bool
    {
        return $options->stopOnBudget
            && $options->maxTotalCostUsd !== null
            && $spent > $options->maxTotalCostUsd;
    }

    private function describe(Throwable $e): string
    {
        return $e::class.': '.$e->getMessage();
    }
}

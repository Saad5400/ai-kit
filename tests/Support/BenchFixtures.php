<?php

namespace Saad\AiKit\Tests\Support;

use Closure;
use Illuminate\Auth\GenericUser;
use Saad\AiKit\Bench\Grader;
use Saad\AiKit\Bench\Scenario;
use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Turn;
use Saad\AiKit\Bench\TurnRecord;
use Saad\AiKit\Bench\Verdict;

final class BenchFixtures
{
    public static function actor(): GenericUser
    {
        return new GenericUser(['id' => 7, 'name' => 'Teacher']);
    }

    /**
     * @param  list<Turn>  $turns
     * @param  list<Grader>  $graders
     * @param  array<string, mixed>  $extra
     */
    public static function scenario(array $turns, array $graders = [], array $extra = []): Scenario
    {
        return new Scenario(
            name: $extra['name'] ?? 'test.scenario',
            title: $extra['title'] ?? 'سيناريو اختبار',
            turns: $turns,
            graders: $graders,
            tags: $extra['tags'] ?? ['test'],
            seed: $extra['seed'] ?? fn () => ['course_id' => 42],
            actor: array_key_exists('actor', $extra) ? $extra['actor'] : fn ($ctx) => self::actor(),
            teardown: $extra['teardown'] ?? null,
            expectedLanguage: array_key_exists('expectedLanguage', $extra) ? $extra['expectedLanguage'] : 'ar',
            maxCostUsd: $extra['maxCostUsd'] ?? null,
            maxWallMs: $extra['maxWallMs'] ?? null,
        );
    }

    /**
     * A finished run holding the given records (one scripted turn per record).
     *
     * @param  list<TurnRecord>  $records
     * @param  array<string, mixed>  $extra
     */
    public static function run(array $records, array $extra = []): ScenarioRun
    {
        $turns = array_map(fn (int $i) => new Turn("prompt {$i}"), array_keys($records));
        $run = new ScenarioRun(self::scenario($turns ?: [new Turn('prompt')], extra: $extra), 1, ['course_id' => 42]);

        foreach ($records as $index => $record) {
            $run->turnBoundaries[] = $index;
            $run->addTurn($record);
        }

        return $run;
    }

    public static function grader(string $name, Closure $fn): Grader
    {
        return new class($name, $fn) implements Grader
        {
            public function __construct(private string $name, private Closure $fn) {}

            public function name(): string
            {
                return $this->name;
            }

            public function grade(ScenarioRun $run): Verdict
            {
                return ($this->fn)($run);
            }
        };
    }
}

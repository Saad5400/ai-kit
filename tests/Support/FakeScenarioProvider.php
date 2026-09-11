<?php

namespace Saad\AiKit\Tests\Support;

use Saad\AiKit\Bench\Graders\ReplyNotEmpty;
use Saad\AiKit\Bench\Graders\ToolsCalled;
use Saad\AiKit\Bench\ScenarioProvider;
use Saad\AiKit\Bench\Turn;

final class FakeScenarioProvider implements ScenarioProvider
{
    public function scenarios(): iterable
    {
        yield BenchFixtures::scenario(
            [new Turn('أنشئ مقرر')],
            [new ReplyNotEmpty, new ToolsCalled(all: ['UpsertCourse'])],
            ['name' => 'structure.create-course', 'tags' => ['structure', 'ar']],
        );

        yield BenchFixtures::scenario(
            [new Turn('احذف المقرر')],
            [new ReplyNotEmpty],
            ['name' => 'structure.delete-course', 'tags' => ['structure', 'destructive']],
        );

        yield BenchFixtures::scenario(
            [new Turn('what is my grade')],
            [new ReplyNotEmpty],
            ['name' => 'grades.lookup', 'tags' => ['grades', 'en'], 'expectedLanguage' => 'en'],
        );
    }
}

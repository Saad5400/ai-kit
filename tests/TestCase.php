<?php

namespace Saad\AiKit\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Saad\AiKit\AiKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AiKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.openrouter.key', 'test-key');

        // The encrypted conversation store needs a real app key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    /**
     * The migrations the migrator would run from the paths the kit registered,
     * named the way the `migrations` table names them: basename, no path.
     *
     * @return list<string>
     */
    protected function migrationNames(): array
    {
        $migrator = $this->app->make('migrator');

        return array_keys($migrator->getMigrationFiles($migrator->paths()));
    }
}

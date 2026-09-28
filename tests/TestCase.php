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

        // Opt-in second database: AI_KIT_TEST_DB_URL=pgsql://user:pass@host/db
        // runs the suite against Postgres (production's engine) instead of
        // the default in-memory sqlite. The conversation suites (store, steps
        // migration, prune) are Postgres-clean; the module-toggle Overrides
        // suites assume a fresh database per test case, which a shared
        // Postgres database is not.
        if (is_string($url = getenv('AI_KIT_TEST_DB_URL')) && $url !== '') {
            $app['config']->set('database.connections.kit_test', ['driver' => parse_url($url, PHP_URL_SCHEME) === 'pgsql' ? 'pgsql' : 'mysql', 'url' => $url]);
            $app['config']->set('database.default', 'kit_test');
        }
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

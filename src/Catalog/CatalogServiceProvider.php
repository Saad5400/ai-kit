<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Catalog\Console\SyncModelsCommand;
use Saad\AiKit\Support\LoadsKitMigrations;

class CatalogServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

    public function register(): void
    {
        $this->app->singleton(ConfigCatalogSource::class, fn (Application $app) => new ConfigCatalogSource(
            $app['config'],
        ));

        $this->app->singleton(DatabaseCatalogSource::class);

        // 'config' reads ai-kit.catalog.models live; 'database' reads the
        // ai_models table `ai-kit:sync-models` materializes from that same
        // config — the reviewed config stays the source of truth either way.
        $this->app->singleton(CatalogSource::class, fn (Application $app) => $app->make(
            $app['config']->get('ai-kit.catalog.source', 'config') === 'database'
                ? DatabaseCatalogSource::class
                : ConfigCatalogSource::class,
        ));

        $this->app->singleton(Catalog::class);

        $this->app->singleton(ModelRouting::class, fn (Application $app) => new ModelRouting(
            $app->make(CatalogSource::class),
        ));
    }

    public function boot(): void
    {
        // The ai_models table exists only for database-sourced catalogs —
        // config-catalog apps must not grow an empty table.
        if ($this->app['config']->get('ai-kit.catalog.source', 'config') === 'database') {
            $this->loadKitMigrations(__DIR__.'/../../database/migrations/catalog');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncModelsCommand::class]);
        }

        $this->publishModelExtremes();
    }

    /**
     * Feed the catalog's cheapest/smartest declarations into the provider
     * config keys the SDK's UseCheapestModel / UseSmartestModel attributes
     * read.
     */
    protected function publishModelExtremes(): void
    {
        $config = $this->app['config'];
        $provider = $config->get('ai-kit.catalog.provider', 'openrouter');

        foreach (['cheapest', 'smartest'] as $extreme) {
            $model = $config->get("ai-kit.catalog.{$extreme}");

            if ($model !== null && $config->get("ai.providers.{$provider}.models.text.{$extreme}") === null) {
                $config->set("ai.providers.{$provider}.models.text.{$extreme}", $model);
            }
        }
    }
}

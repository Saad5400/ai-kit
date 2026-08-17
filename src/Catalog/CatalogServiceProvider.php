<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Catalog\Console\SyncModelsCommand;

class CatalogServiceProvider extends ServiceProvider
{
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

        $this->app->singleton(FallbackChains::class, fn (Application $app) => new FallbackChains(
            $app->make(CatalogSource::class),
            $app['config']->get('ai-kit.catalog.provider', 'openrouter'),
        ));
    }

    public function boot(): void
    {
        // The ai_models table exists only for database-sourced catalogs —
        // config-catalog apps must not grow an empty table.
        if ($this->app['config']->get('ai-kit.catalog.source', 'config') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/catalog');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncModelsCommand::class]);
        }

        $this->registerProviderAliases();
        $this->publishModelExtremes();
    }

    /**
     * Register the alias provider entries fallback chains resolve through.
     * Each alias clones the base provider's config; the manager injects the
     * alias as `name`, and the shared driver routes it through our gateway.
     * App-defined entries of the same name are never overwritten.
     */
    protected function registerProviderAliases(): void
    {
        $config = $this->app['config'];
        $provider = $config->get('ai-kit.catalog.provider', 'openrouter');
        $base = $config->get("ai.providers.{$provider}");

        if ($base === null) {
            return;
        }

        $count = $this->app->make(FallbackChains::class)->aliasCount();

        for ($index = 0; $index < $count; $index++) {
            $alias = FallbackChains::alias($provider, $index);

            if ($config->get("ai.providers.{$alias}") === null) {
                $config->set("ai.providers.{$alias}", $base);
            }
        }
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

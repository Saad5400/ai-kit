<?php

namespace Saad\AiKit;

use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Support\LoadsKitMigrations;

class AiKitServiceProvider extends ServiceProvider
{
    use LoadsKitMigrations;

    /**
     * Module providers, keyed by their `ai-kit.modules.*` config toggle.
     *
     * @var array<string, class-string<ServiceProvider>>
     */
    protected array $modules = [
        'gateway' => Gateway\GatewayServiceProvider::class,
        'agents' => Agents\AgentsServiceProvider::class,
        'conversations' => Conversations\ConversationsServiceProvider::class,
        'streaming' => Streaming\StreamingServiceProvider::class,
        'approvals' => Approvals\ApprovalsServiceProvider::class,
        'attachments' => Attachments\AttachmentsServiceProvider::class,
        'usage' => Usage\UsageServiceProvider::class,
        'catalog' => Catalog\CatalogServiceProvider::class,
        'safety' => Safety\SafetyServiceProvider::class,
        'rag' => Rag\RagServiceProvider::class,
        'credits' => Credits\CreditsServiceProvider::class,
    ];

    public function register(): void
    {
        $config = $this->app['config'];

        // Deep merge (mergeConfigFrom is shallow): an app overriding a single
        // toggle must not wipe the defaults of its siblings.
        $config->set('ai-kit', array_replace_recursive(
            require __DIR__.'/../config/ai-kit.php',
            $config->get('ai-kit', []),
        ));

        foreach ($this->modules as $toggle => $provider) {
            if ($config->get("ai-kit.modules.{$toggle}") === true) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        // Only the shared migrations live here — currently the laravel/ai
        // conversation tables, which the kit ships on the SDK's behalf and
        // every module's store reads through. Module-owned tables live in
        // `database/migrations/{module}` and are loaded by that module's
        // provider, which only registers when its toggle is on; a consumer
        // that turns a module off must not grow (or collide with) its tables.
        $this->loadKitMigrations(__DIR__.'/../database/migrations');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ai-kit');

        $this->publishes([
            __DIR__.'/../config/ai-kit.php' => config_path('ai-kit.php'),
        ], 'ai-kit-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/ai-kit'),
        ], 'ai-kit-lang');
    }
}

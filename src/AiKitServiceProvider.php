<?php

namespace Saad\AiKit;

use Illuminate\Support\ServiceProvider;

class AiKitServiceProvider extends ServiceProvider
{
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
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ai-kit');

        $this->publishes([
            __DIR__.'/../config/ai-kit.php' => config_path('ai-kit.php'),
        ], 'ai-kit-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/ai-kit'),
        ], 'ai-kit-lang');
    }
}

<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Saad\AiKit\Conversations\Console\PruneConversationsCommand;

class ConversationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversationOwnership::class, fn ($app) => new ConversationOwnership(
            $app['config']->get('ai.conversations.connection'),
        ));

        // Rebindable on purpose: this registers after laravel/ai's provider
        // (kit depends on it) so the encrypted store wins over the vendor
        // default, and an app binding registered later wins over the kit.
        // With encrypt off, the vendor store binding is left untouched.
        if ($this->app['config']->get('ai-kit.conversations.encrypt', true) === true) {
            $this->app->singleton(ConversationStore::class, fn ($app) => new EncryptedConversationStore(
                $app['config']->get('ai.conversations.connection'),
            ));
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'ai-kit-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneConversationsCommand::class]);
        }
    }
}

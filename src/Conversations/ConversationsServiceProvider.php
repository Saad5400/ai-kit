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

        // On by default (owner decision: encryption everywhere, per-app
        // opt-out). Binding this store rewrites how every message row is
        // written from that deploy on and there is no going back for the
        // rows already encrypted. Rebindable on purpose: this registers
        // after laravel/ai's provider (kit depends on it) so the encrypted
        // store wins over the vendor default, and an app binding registered
        // later wins over the kit.
        if ($this->app['config']->get('ai-kit.conversations.encrypt', true) === true) {
            $this->app->singleton(ConversationStore::class, fn ($app) => new EncryptedConversationStore(
                $app['config']->get('ai.conversations.connection'),
            ));
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneConversationsCommand::class]);
        }
    }
}

<?php

use Saad\AiKit\Approvals\ApprovalsServiceProvider;
use Saad\AiKit\Catalog\CatalogServiceProvider;
use Saad\AiKit\Conversations\ConversationsServiceProvider;
use Saad\AiKit\Credits\CreditsServiceProvider;
use Saad\AiKit\Gateway\GatewayServiceProvider;
use Saad\AiKit\Rag\RagServiceProvider;
use Saad\AiKit\Safety\SafetyServiceProvider;
use Saad\AiKit\Streaming\StreamingServiceProvider;
use Saad\AiKit\Usage\UsageServiceProvider;

it('registers default-enabled module providers', function () {
    foreach ([
        GatewayServiceProvider::class,
        ConversationsServiceProvider::class,
        StreamingServiceProvider::class,
        ApprovalsServiceProvider::class,
        UsageServiceProvider::class,
        CatalogServiceProvider::class,
        SafetyServiceProvider::class,
    ] as $provider) {
        expect($this->app->providerIsLoaded($provider))->toBeTrue("Expected {$provider} to be registered");
    }
});

it('does not register opt-in modules by default', function () {
    expect($this->app->providerIsLoaded(CreditsServiceProvider::class))->toBeFalse()
        ->and($this->app->providerIsLoaded(RagServiceProvider::class))->toBeFalse();
});

it('merges the package config', function () {
    expect(config('ai-kit.modules'))->toBeArray()->toHaveKey('gateway');
});

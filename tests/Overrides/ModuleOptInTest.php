<?php

use Saad\AiKit\Credits\CreditsServiceProvider;
use Saad\AiKit\Gateway\GatewayServiceProvider;
use Saad\AiKit\Tests\ModulesOverriddenTestCase;

uses(ModulesOverriddenTestCase::class);

it('registers opt-in modules when enabled and skips disabled ones', function () {
    expect($this->app->providerIsLoaded(CreditsServiceProvider::class))->toBeTrue()
        ->and($this->app->providerIsLoaded(GatewayServiceProvider::class))->toBeFalse();
});

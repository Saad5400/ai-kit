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
    }
}

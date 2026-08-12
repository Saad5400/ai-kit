<?php

namespace Saad\AiKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Saad\AiKit\AiKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiKitServiceProvider::class];
    }
}

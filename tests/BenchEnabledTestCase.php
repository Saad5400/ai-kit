<?php

namespace Saad\AiKit\Tests;

abstract class BenchEnabledTestCase extends TestCase
{
    /**
     * Module toggles are read when providers register, which Testbench runs
     * before defineEnvironment() — so the opt-in must land at configuration
     * resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.modules.bench', true);
    }
}

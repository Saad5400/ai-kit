<?php

namespace Saad\AiKit\Tests;

abstract class ModulesOverriddenTestCase extends TestCase
{
    /**
     * Module toggles are read when providers register, which Testbench runs
     * before defineEnvironment() — so overrides must land at configuration
     * resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.modules.credits', true);
        $app['config']->set('ai-kit.modules.gateway', false);
    }
}

<?php

namespace Saad\AiKit\Tests;

abstract class ApprovalsDisabledTestCase extends TestCase
{
    /**
     * Module toggles are read when providers register, which Testbench runs
     * before defineEnvironment() — so overrides must land at configuration
     * resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.modules.approvals', false);
    }
}

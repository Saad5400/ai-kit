<?php

namespace Saad\AiKit\Tests;

abstract class UndoEnabledTestCase extends TestCase
{
    /**
     * The undo opt-in is read when the approvals provider boots, which
     * Testbench runs before defineEnvironment() — so the override must land
     * at configuration resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.approvals.undo', true);
    }
}

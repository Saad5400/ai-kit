<?php

namespace Saad\AiKit\Tests;

abstract class ConversationsEncryptEnabledTestCase extends TestCase
{
    /**
     * The encrypt toggle is read when the conversations provider registers,
     * which Testbench runs before defineEnvironment() — so the opt-in must
     * land at configuration resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.conversations.encrypt', true);
    }
}

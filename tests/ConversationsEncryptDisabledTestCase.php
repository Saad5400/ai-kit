<?php

namespace Saad\AiKit\Tests;

abstract class ConversationsEncryptDisabledTestCase extends TestCase
{
    /**
     * The encrypt toggle is read when the conversations provider registers,
     * which Testbench runs before defineEnvironment() — so the opt-out must
     * land at configuration resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.conversations.encrypt', false);
    }
}

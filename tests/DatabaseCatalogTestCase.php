<?php

namespace Saad\AiKit\Tests;

abstract class DatabaseCatalogTestCase extends TestCase
{
    /**
     * The catalog source is read when the catalog provider registers/boots,
     * which Testbench runs before defineEnvironment() — so the override must
     * land at configuration resolution time.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ai-kit.catalog.source', 'database');
        $app['config']->set('ai-kit.catalog.models', [
            'test/flash' => [
                'label' => 'Flash',
                'input_usd_per_million' => 0.30,
                'output_usd_per_million' => 2.50,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision'],
                'tasks' => ['chat', 'mcq'],
                'tags' => ['recommended'],
                'fallbacks' => ['test/lite'],
                'provider_max_price' => ['prompt' => 0.5, 'completion' => 3.25],
                'sort_order' => 1,
                'meta' => ['tier' => 'standard'],
            ],
            'test/lite' => [
                'label' => 'Lite',
                'tasks' => ['chat'],
                'sort_order' => 2,
            ],
        ]);
    }
}

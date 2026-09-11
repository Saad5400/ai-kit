<?php

namespace Saad\AiKit\Bench;

/**
 * An app-side source of scenarios, listed in `ai-kit.bench.providers`.
 */
interface ScenarioProvider
{
    /**
     * @return iterable<Scenario>
     */
    public function scenarios(): iterable;
}

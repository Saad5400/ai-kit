<?php

namespace Saad\AiKit\Usage;

use Laravel\Ai\Prompts\AgentPrompt;
use Saad\AiKit\Streaming\Events\TurnStopped;

/**
 * The prompts of the runs in flight in this request / job, by invocation
 * id — what a stopped run's usage row is built from, since the stop itself
 * ({@see TurnStopped}) carries only the id.
 * Scoped, and forgotten as each run is recorded.
 */
class ActiveRuns
{
    /** @var array<string, AgentPrompt> */
    protected array $prompts = [];

    public function remember(string $invocationId, AgentPrompt $prompt): void
    {
        $this->prompts[$invocationId] = $prompt;
    }

    public function prompt(string $invocationId): ?AgentPrompt
    {
        return $this->prompts[$invocationId] ?? null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->prompts[$invocationId]);
    }
}

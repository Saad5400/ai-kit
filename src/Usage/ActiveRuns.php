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

    /**
     * Whether another run is still in flight — i.e. `$invocationId` runs
     * NESTED inside it (a sub-agent called from a tool).
     */
    public function hasOtherThan(string $invocationId): bool
    {
        return array_diff_key($this->prompts, [$invocationId => true]) !== [];
    }

    /**
     * Forget every run — a new top-level turn starts (a run abandoned
     * without an outcome must not make the next one look nested).
     */
    public function flush(): void
    {
        $this->prompts = [];
    }
}

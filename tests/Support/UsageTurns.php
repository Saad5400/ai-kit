<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * A completed agent turn's vendor event, for the usage suites — shared, so
 * each suite also runs alone and under --parallel.
 */
final class UsageTurns
{
    public static function agent(): Agent
    {
        return new class implements Agent
        {
            use Promptable;

            public function instructions(): string
            {
                return 'test agent';
            }
        };
    }

    /**
     * @return array{0: AgentPrompted|AgentStreamed, 1: AgentResponse}
     */
    public static function prompted(bool $streamed = false, ?TextUsage $usage = null, ?string $invocationId = null): array
    {
        $invocationId ??= (string) Str::uuid7();

        $prompt = new AgentPrompt(
            self::agent(),
            'hello',
            [],
            app(AiManager::class)->textProvider('openrouter'),
            'test/model',
            invocationId: $invocationId,
        );

        $response = new AgentResponse(
            $invocationId,
            'response text',
            $usage ?? new TextUsage(inputTokens: 100, outputTokens: 25, reasoningTokens: 5),
            new Meta(provider: 'openrouter', model: 'test/model'),
        );

        $event = $streamed
            ? new AgentStreamed($invocationId, $prompt, $response)
            : new AgentPrompted($invocationId, $prompt, $response);

        return [$event, $response];
    }
}

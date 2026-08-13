<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Tools\Request;
use ReflectionMethod;
use Saad\AiKit\Gateway\ModelCircuitBreaker;
use Saad\AiKit\Gateway\ReasoningOpenRouterGateway;
use Saad\AiKit\Gateway\SpendCollector;
use Stringable;

class GatewayFactory
{
    public static function gateway(array $config = [], ?ModelCircuitBreaker $breaker = null): ReasoningOpenRouterGateway
    {
        return new ReasoningOpenRouterGateway(
            app('events'),
            app(SpendCollector::class),
            array_replace_recursive(config('ai-kit.gateway'), $config),
            $breaker,
        );
    }

    public static function provider(): OpenRouterProvider
    {
        return new OpenRouterProvider(
            ['name' => 'openrouter', 'driver' => 'openrouter', 'key' => 'test-key'],
            app('events'),
        );
    }

    /**
     * Drive processTextStream over the given SSE frames.
     *
     * @return array{events: array, step: mixed}
     */
    public static function streamed(ReasoningOpenRouterGateway $gateway, array $frames, array &$events = []): mixed
    {
        $method = new ReflectionMethod($gateway, 'processTextStream');

        $generator = $method->invoke($gateway, 'inv-test', static::provider(), 'test/model', OpenRouterSse::stream($frames));

        foreach ($generator as $event) {
            $events[] = $event;
        }

        return $generator->getReturn();
    }

    public static function buildStepBody(ReasoningOpenRouterGateway $gateway, mixed ...$args): array
    {
        return (new ReflectionMethod($gateway, 'buildStepBody'))->invoke($gateway, ...$args);
    }

    public static function fakeTool(): Tool
    {
        return new class implements Tool
        {
            public function description(): Stringable|string
            {
                return 'A test tool.';
            }

            public function handle(Request $request): Stringable|string
            {
                return 'ok';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
    }
}

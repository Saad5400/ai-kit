<?php

namespace Saad\AiKit\Tests\Support;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Builds OpenRouter chat-completion SSE bodies matching the frame shapes
 * the gateway parses: delta chunks, usage-only frames, error frames.
 */
class OpenRouterSse
{
    public static function body(array $frames): string
    {
        $lines = array_map(
            fn (array $frame): string => 'data: '.json_encode($frame, JSON_UNESCAPED_UNICODE)."\n\n",
            $frames,
        );

        return implode('', $lines)."data: [DONE]\n\n";
    }

    public static function stream(array $frames): StreamInterface
    {
        return Utils::streamFor(static::body($frames));
    }

    /**
     * A delta chunk: one choice carrying the given delta.
     */
    public static function chunk(
        array $delta,
        ?string $finishReason = null,
        string $id = 'gen-test-1',
        string $model = 'test/model',
        array $extra = [],
    ): array {
        $choice = array_merge(['delta' => $delta], $extra);

        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        return ['id' => $id, 'model' => $model, 'choices' => [$choice]];
    }

    /**
     * The trailing choice-less usage frame OpenRouter sends with
     * stream_options.include_usage.
     */
    public static function usageFrame(array $usage, string $id = 'gen-test-1'): array
    {
        return ['id' => $id, 'model' => 'test/model', 'usage' => $usage];
    }

    /**
     * A complete non-streamed chat completion body.
     */
    public static function completion(
        string $content = 'Hello.',
        ?array $usage = null,
        string $id = 'gen-test-1',
        string $model = 'test/model',
        ?array $toolCalls = null,
    ): array {
        $message = ['role' => 'assistant', 'content' => $content];

        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $id,
            'model' => $model,
            'choices' => [[
                'message' => $message,
                'finish_reason' => $toolCalls !== null ? 'tool_calls' : 'stop',
            ]],
            'usage' => $usage ?? ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
    }
}

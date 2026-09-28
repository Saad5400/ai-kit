<?php

namespace Saad\AiKit\Conversations;

/**
 * The `steps` column shape laravel/ai 1.0 stores on an assistant message —
 * one entry per model round trip, each tool result folded onto the call
 * that produced it — plus the content-only reduction the kit writes when
 * tool traces are off or past their retention window.
 *
 * Shared by the encrypted store, the 0.10 → 1.0 steps backfill and the
 * prune command, so all three write the same shape.
 */
final class StoredSteps
{
    /**
     * Build one stored step.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  array<array-key, mixed>  $replayBlocks
     * @return array{content: string, tool_calls: list<array<string, mixed>>, reasoning: string, replay_blocks: array<array-key, mixed>, provider_tool_calls: list<array<string, mixed>>}
     */
    public static function step(string $content, array $toolCalls = [], string $reasoning = '', array $replayBlocks = []): array
    {
        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'reasoning' => $reasoning,
            'replay_blocks' => $replayBlocks,
            'provider_tool_calls' => [],
        ];
    }

    /**
     * Reduce a turn's steps to the text the user saw: ONE step carrying the
     * steps' non-blank contents joined (or $fallback when no step carried
     * any), with no tool calls, reasoning or replay blocks. A turn with no
     * text at all reduces to no steps.
     *
     * One step, not one per round trip: with the tool calls gone, several
     * content steps would replay as consecutive assistant messages.
     *
     * @param  iterable<int, array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    public static function contentOnly(iterable $steps, ?string $fallback = null): array
    {
        $contents = [];

        foreach ($steps as $step) {
            $content = is_array($step) ? (string) ($step['content'] ?? '') : '';

            if (trim($content) !== '') {
                $contents[] = $content;
            }
        }

        $content = $contents === [] ? (string) $fallback : implode("\n\n", $contents);

        return $content === '' ? [] : [self::step($content)];
    }

    /**
     * Whether any step carries more than its text.
     *
     * @param  iterable<int, array<string, mixed>>  $steps
     */
    public static function carriesTraces(iterable $steps): bool
    {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            if (($step['tool_calls'] ?? []) !== [] || ($step['provider_tool_calls'] ?? []) !== []
                || ($step['replay_blocks'] ?? []) !== [] || (string) ($step['reasoning'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Saad\AiKit\Support;

use Illuminate\Support\Facades\Context;

/**
 * The Context keys a turn's timing travels through. The gateway stamps time
 * to first token from inside the stream loop (it has no invocation id at
 * that depth), the usage listeners stamp the start and consume everything
 * when the turn is recorded. Turns never interleave inside one process, so
 * the unkeyed values are safe; the start stamp is keyed by invocation id so
 * a crashed turn's leftovers can never bleed into the next one's duration.
 */
class TurnContext
{
    public const CURRENT_INVOCATION_KEY = 'ai-kit.turn.current_invocation';

    public const TTFT_KEY = 'ai-kit.turn.ttft_ms';

    public static function startedAtKey(string $invocationId): string
    {
        return "ai-kit.turn.{$invocationId}.started_at_ms";
    }

    public static function stampStart(string $invocationId): void
    {
        Context::addIf(static::startedAtKey($invocationId), static::nowMs());

        Context::add(static::CURRENT_INVOCATION_KEY, $invocationId);
    }

    /**
     * Stamp time-to-first-token once per turn, measured against the start
     * stamp. A no-op when no start was stamped (usage module disabled) or a
     * TTFT is already recorded for this turn.
     */
    public static function stampTtftOnce(): void
    {
        $invocationId = Context::get(static::CURRENT_INVOCATION_KEY);

        if ($invocationId === null || Context::get(static::TTFT_KEY) !== null) {
            return;
        }

        $startedAt = Context::get(static::startedAtKey($invocationId));

        if ($startedAt !== null) {
            Context::add(static::TTFT_KEY, max(0, static::nowMs() - $startedAt));
        }
    }

    /**
     * Read the turn's duration and TTFT, then clear every stamp.
     *
     * @return array{0: ?int, 1: ?int} [durationMs, ttftMs]
     */
    public static function consume(string $invocationId): array
    {
        $startedAt = Context::get(static::startedAtKey($invocationId));
        $ttft = Context::get(static::TTFT_KEY);

        Context::forget([
            static::startedAtKey($invocationId),
            static::TTFT_KEY,
            static::CURRENT_INVOCATION_KEY,
        ]);

        return [
            $startedAt !== null ? max(0, static::nowMs() - $startedAt) : null,
            $ttft,
        ];
    }

    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}

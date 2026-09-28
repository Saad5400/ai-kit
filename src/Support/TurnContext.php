<?php

namespace Saad\AiKit\Support;

use Illuminate\Support\Facades\Context;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Streaming\TurnRunner;

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

    public const FLAGS_KEY = 'ai-kit.turn.flags';

    /** The app's id for the turn being run (TurnRunner's `$turnId`). */
    public const TURN_ID_KEY = 'ai-kit.turn.id';

    /** App metadata for the turn, handed to InterruptedSpendResolved. */
    public const TURN_META_KEY = 'ai-kit.turn.meta';

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

    /**
     * Record a fact about the turn the gateway's step guard observed —
     * `wrap_up` (why an extra answer-now completion ran), `markup_leak`,
     * `markup_salvaged`, `markup_retried` — for the usage row's `context`
     * column. A value already set for a key is kept: the first cause wins,
     * and a turn is flagged once however many steps repeated the symptom.
     */
    public static function flag(string $key, mixed $value): void
    {
        $flags = Context::get(static::FLAGS_KEY, []);

        if (! is_array($flags)) {
            $flags = [];
        }

        if (array_key_exists($key, $flags)) {
            return;
        }

        $flags[$key] = $value;

        Context::add(static::FLAGS_KEY, $flags);
    }

    /**
     * The flags recorded so far, without clearing them.
     *
     * @return array<string, mixed>
     */
    public static function flags(): array
    {
        $flags = Context::get(static::FLAGS_KEY, []);

        return is_array($flags) ? $flags : [];
    }

    /**
     * Read the turn's flags and clear them — the usage listener's half.
     *
     * @return array<string, mixed>
     */
    public static function consumeFlags(): array
    {
        $flags = static::flags();

        Context::forget(static::FLAGS_KEY);

        return $flags;
    }

    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Open a top-level turn: name it, attach the app's metadata, and start
     * the spend collector clean. {@see TurnRunner}
     * calls this for every run; an app that drives a turn without the runner
     * calls it (and {@see endTurn()}) itself.
     *
     * The flush is not redundant with the queue worker: the worker gives
     * every job a fresh Context, but a `dispatchSync()` / sync-driver job
     * re-hydrates its CALLER's Context, and an HTTP-inline turn or a job
     * running several turns shares one — so rounds another turn left on the
     * collector would be absorbed into this turn's usage row. Skipped when
     * `ai-kit.usage.drain_spend` is off: the app owns the collector then.
     *
     * Hidden context, so the metadata stays out of log records.
     *
     * @param  array<string, mixed>  $meta  must be queue-serialisable
     */
    public static function beginTurn(string $turnId, array $meta = []): void
    {
        if (config('ai-kit.usage.drain_spend', true) && app()->bound(SpendCollector::class)) {
            app(SpendCollector::class)->flush();
        }

        Context::addHidden(static::TURN_ID_KEY, $turnId);
        Context::addHidden(static::TURN_META_KEY, $meta);
    }

    public static function endTurn(): void
    {
        Context::forgetHidden([static::TURN_ID_KEY, static::TURN_META_KEY]);
    }

    public static function turnId(): ?string
    {
        $id = Context::getHidden(static::TURN_ID_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function turnMeta(): array
    {
        $meta = Context::getHidden(static::TURN_META_KEY, []);

        return is_array($meta) ? $meta : [];
    }
}

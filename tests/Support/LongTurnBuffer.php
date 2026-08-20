<?php

namespace Saad\AiKit\Tests\Support;

use Saad\AiKit\Testing\FakeTurnBuffer;

/**
 * TEMPORARY K1 shim: a {@see FakeTurnBuffer} that adds the `touch()` /
 * `upsert()` the long-turns TurnBuffer (branch `claude/long-turns-buffer`)
 * is adding, with the exact semantics that branch specifies, so the
 * runner/progress suites can exercise them before the branches merge.
 * Once K1 lands on main, delete the local method bodies and keep only the
 * spy counters delegating to `parent::`.
 *
 * `$touches` counts heartbeat touches (implicit ones via append excluded)
 * — the observable tests need that "a throttled report still proves life".
 */
class LongTurnBuffer extends FakeTurnBuffer
{
    public int $touches = 0;

    /**
     * Re-stamp the heartbeat without appending. Mirrors K1: a no-op on an
     * unknown turn; slides the TTL.
     */
    public function touch(string $turnId): void
    {
        $this->touches++;

        $turn = $this->get($turnId);

        if ($turn === null) {
            return;
        }

        $turn['heartbeat_at'] = microtime(true);

        $this->cache->put($this->key($turnId), $turn, $this->ttlSeconds);
    }

    /**
     * Mirrors K1: if the LAST event in the log has the same `$event` and
     * the same `$data[$key]`, replace it in place with a fresh seq (so
     * resuming clients still receive it); otherwise append. No-op once the
     * turn is terminal.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(string $turnId, string $event, array $data, string $key = 'id'): void
    {
        $turn = $this->get($turnId);

        if ($turn === null || $turn['status'] !== 'running') {
            return;
        }

        $lastIndex = array_key_last($turn['events']);
        $last = $lastIndex !== null ? $turn['events'][$lastIndex] : null;

        if (
            $last !== null
            && $last['event'] === $event
            && isset($data[$key])
            && ($last['data'][$key] ?? null) === $data[$key]
        ) {
            $turn['cursor']++;
            $turn['events'][$lastIndex] = ['seq' => $turn['cursor'], 'event' => $event, 'data' => $data];

            $this->cache->put($this->key($turnId), $turn, $this->ttlSeconds);

            return;
        }

        $this->append($turnId, $event, $data);
    }
}

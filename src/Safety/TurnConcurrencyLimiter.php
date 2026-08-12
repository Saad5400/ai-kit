<?php

namespace Saad\AiKit\Safety;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Saad\AiKit\Safety\Exceptions\TooManyConcurrentTurnsException;

/**
 * Per-owner cap on concurrently running turns. Counter-based with a TTL
 * backstop: a crashed worker leaves its slot occupied only until the TTL
 * expires, then the limiter fails open. Configure the TTL above the
 * longest legitimate turn duration.
 */
class TurnConcurrencyLimiter
{
    public function __construct(
        protected Repository $cache,
        protected ?int $maxConcurrent,
        protected int $ttlSeconds,
    ) {}

    /**
     * @throws TooManyConcurrentTurnsException
     */
    public function acquire(string $owner): void
    {
        if ($this->maxConcurrent === null) {
            return;
        }

        $key = $this->key($owner);

        $this->cache->add($key, 0, $this->ttlSeconds);

        if ($this->cache->increment($key) > $this->maxConcurrent) {
            $this->cache->decrement($key);

            throw new TooManyConcurrentTurnsException($owner, $this->maxConcurrent);
        }
    }

    public function release(string $owner): void
    {
        if ($this->maxConcurrent === null) {
            return;
        }

        $key = $this->key($owner);

        if (((int) $this->cache->get($key, 0)) > 0) {
            $this->cache->decrement($key);
        }
    }

    /**
     * Run the callback while holding a slot, releasing it even on failure.
     *
     * @throws TooManyConcurrentTurnsException
     */
    public function run(string $owner, Closure $callback): mixed
    {
        $this->acquire($owner);

        try {
            return $callback();
        } finally {
            $this->release($owner);
        }
    }

    public function inFlight(string $owner): int
    {
        return (int) $this->cache->get($this->key($owner), 0);
    }

    protected function key(string $owner): string
    {
        return 'ai-kit:turns:'.$owner;
    }
}

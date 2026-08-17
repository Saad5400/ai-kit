<?php

namespace Saad\AiKit\Testing;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Saad\AiKit\Streaming\TurnBuffer;

/**
 * A {@see TurnBuffer} over its own private array cache, so tests exercise
 * the real append/tail/claim semantics without a shared store or a container
 * binding — and without one test's turns leaking into another's. Polling
 * delays are zeroed so a tail over an already-finished turn never sleeps.
 */
class FakeTurnBuffer extends TurnBuffer
{
    public function __construct(int $ttlSeconds = 7200)
    {
        parent::__construct(
            cache: new Repository(new ArrayStore),
            ttlSeconds: $ttlSeconds,
            maxStreamSeconds: 5,
            keepaliveSeconds: 1,
            pollIntervalMs: 0,
        );
    }
}

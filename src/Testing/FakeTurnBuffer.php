<?php

namespace Saad\AiKit\Testing;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Saad\AiKit\Streaming\TurnBuffer;

/**
 * A {@see TurnBuffer} over its own private array cache, so tests exercise
 * the real append/upsert/touch/tail/claim semantics without a shared store
 * or a container binding — and without one test's turns leaking into
 * another's. Polling delays are zeroed so a tail over an already-finished
 * turn never sleeps. `$pageSize` is exposed so a test can force page
 * boundaries with a handful of events; `$staleAfterSeconds` so it can
 * drive the stale path with a short travel.
 */
class FakeTurnBuffer extends TurnBuffer
{
    public function __construct(
        int $ttlSeconds = 7200,
        int $pageSize = 64,
        int $staleAfterSeconds = 300,
        bool $staleTrailingDone = false,
    ) {
        parent::__construct(
            cache: new Repository(new ArrayStore),
            ttlSeconds: $ttlSeconds,
            maxStreamSeconds: 5,
            keepaliveSeconds: 1,
            pollIntervalMs: 0,
            pageSize: $pageSize,
            staleAfterSeconds: $staleAfterSeconds,
            staleTrailingDone: $staleTrailingDone,
        );
    }
}

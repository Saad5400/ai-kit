<?php

namespace Saad\AiKit\Tests\Support;

use Saad\AiKit\Testing\FakeTurnBuffer;

/**
 * A {@see FakeTurnBuffer} that counts explicit heartbeat touches — the
 * observable behind "a throttled progress report / cancel poll still
 * proves life". All semantics are the real buffer's; only the counter is
 * added.
 */
class SpyTurnBuffer extends FakeTurnBuffer
{
    public int $touches = 0;

    public function touch(string $turnId): void
    {
        $this->touches++;

        parent::touch($turnId);
    }
}

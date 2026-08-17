<?php

namespace Saad\AiKit\Streaming;

/**
 * A stateful transformer on the mapper's text channel. Deltas arrive a few
 * characters at a time, so a transformer may hold text back until it can be
 * judged whole (uqucc's streaming link guard holds a possible link until it
 * completes): {@see push} feeds one delta and returns whatever is safe to
 * emit now; {@see flush} releases anything still held when the stream ends.
 */
interface TextTransformer
{
    public function push(string $delta): string;

    public function flush(): string;
}

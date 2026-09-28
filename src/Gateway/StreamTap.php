<?php

namespace Saad\AiKit\Gateway;

/**
 * One streamed step's SSE body plus what the gateway reads off its chunks
 * on the way into stock laravel/ai's stream loop.
 *
 * The gateway hands this to the stock `processTextStream()` in place of the
 * raw body; its `parseServerSentEvents()` override recognises it, reads the
 * real body, and taps every decoded chunk before stock sees it. Keeping the
 * state on the body (not on the gateway, which the provider caches per
 * worker) means two steps can never share it.
 */
class StreamTap
{
    /** The latest generation id seen — every chunk carries it. */
    public ?string $generationId = null;

    /** The upstream OpenRouter routed to — a markup-leak retry excludes it. */
    public ?string $upstream = null;

    /** The last non-zero `usage.cost` seen. */
    public ?float $cost = null;

    public function __construct(
        public readonly mixed $body,
        public readonly MarkupLeakFilter $filter,
    ) {}
}

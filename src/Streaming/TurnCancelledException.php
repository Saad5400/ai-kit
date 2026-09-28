<?php

namespace Saad\AiKit\Streaming;

use RuntimeException;

/**
 * Raised INTO the provider stream when the user stops a turn, so laravel/ai
 * settles it through its own failure path (RememberConversation's catch)
 * instead of the stream being abandoned mid-iteration — an abandoned
 * generator runs neither `then()` nor `catch()`, and the turn would leave no
 * row behind, not even for the tools it already ran.
 *
 * It never escapes the kit: {@see TurnRunner} catches it right back, and the
 * turn still ends as a CANCELLED, completed short turn on the wire. What it
 * leaves is the stored row — `status: failed`, `meta.error` = {@see MESSAGE}
 * — which is how an app tells a stopped turn from a failed one in history.
 */
class TurnCancelledException extends RuntimeException
{
    public const MESSAGE = 'The turn was stopped by the user.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * Whether a stored message's meta marks it as a stopped turn.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function marks(array $meta): bool
    {
        return ($meta['error'] ?? null) === self::MESSAGE;
    }
}

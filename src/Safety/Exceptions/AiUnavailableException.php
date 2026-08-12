<?php

namespace Saad\AiKit\Safety\Exceptions;

use RuntimeException;

/**
 * Base for every "AI refused to start a turn" condition. Callers catch this
 * one type and render userFacingReason() as a normal assistant reply —
 * degraded mode must never surface as a raw 500.
 */
abstract class AiUnavailableException extends RuntimeException
{
    /**
     * A reason safe to show end users, in the current locale.
     */
    abstract public function userFacingReason(): string;

    public function retryAfterSeconds(): ?int
    {
        return null;
    }
}

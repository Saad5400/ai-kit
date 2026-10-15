<?php

namespace Saad\AiKit\Transcription;

use RuntimeException;
use Throwable;

/**
 * Every attempt on the timing model (and its fallback) failed. It still says
 * what those attempts COST: a provider can bill a reply the client cannot use
 * (a 200 with no `words` array), and that spend belongs in the caller's meter.
 */
class WordTimingFailed extends RuntimeException
{
    public function __construct(string $message, public readonly float $spentUsd = 0.0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

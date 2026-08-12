<?php

namespace Saad\AiKit\Safety\Exceptions;

class TooManyConcurrentTurnsException extends AiUnavailableException
{
    public function __construct(
        public readonly string $owner,
        public readonly int $maxConcurrent,
    ) {
        parent::__construct(
            "Owner [{$owner}] already has {$maxConcurrent} turns in flight."
        );
    }

    public function userFacingReason(): string
    {
        return __('ai-kit::safety.too_many_turns');
    }

    public function retryAfterSeconds(): ?int
    {
        return 15;
    }
}

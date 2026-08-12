<?php

namespace Saad\AiKit\Safety\Exceptions;

class BudgetExceededException extends AiUnavailableException
{
    public function __construct(
        public readonly float $spentUsd,
        public readonly ?float $limitUsd,
        protected int $secondsUntilReset,
    ) {
        parent::__construct(sprintf(
            'Daily AI budget exceeded: $%.4f spent of $%.2f limit.',
            $spentUsd,
            $limitUsd ?? 0,
        ));
    }

    public function userFacingReason(): string
    {
        return __('ai-kit::safety.budget_exceeded');
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->secondsUntilReset;
    }
}

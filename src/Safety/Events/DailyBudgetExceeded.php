<?php

namespace Saad\AiKit\Safety\Events;

class DailyBudgetExceeded
{
    public function __construct(
        public readonly float $spentUsd,
        public readonly ?float $limitUsd,
    ) {}
}

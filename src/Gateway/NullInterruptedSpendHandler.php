<?php

namespace Saad\AiKit\Gateway;

/**
 * Default {@see InterruptedSpendHandler}: the budget is still recorded, but
 * nobody is debited until the app binds its own handler.
 */
class NullInterruptedSpendHandler implements InterruptedSpendHandler
{
    public function resolved(string $turnId, float $costUsd, array $generationIds, array $context): void {}
}

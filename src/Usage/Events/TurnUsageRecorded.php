<?php

namespace Saad\AiKit\Usage\Events;

use Saad\AiKit\Usage\UsageEvent;

/**
 * Fires after a turn's usage row is persisted — the hook downstream billing
 * (the credits module's meter), the budget and app-side ledgers subscribe
 * to. Failover attempt rows never fire this event.
 *
 * Since 0.14.1 it fires for INTERRUPTED turns too: a turn the user stopped
 * (`$interruption` = 'stopped', row status `stopped`) or one that failed
 * ('failed', row status `failed`), with the cost of the steps it completed
 * before the stop / failure. Billing is the app's ruling: a stopped turn is
 * debited like a completed one, a failed turn is recorded only (the budget
 * listener counts both). The cost of a step the interruption cut off is
 * learned later and arrives separately, as {@see InterruptedSpendResolved}.
 */
class TurnUsageRecorded
{
    public const STOPPED = 'stopped';

    public const FAILED = 'failed';

    /**
     * @param  self::STOPPED|self::FAILED|null  $interruption  null for a completed or paused turn
     */
    public function __construct(
        public UsageEvent $usage,
        public ?string $interruption = null,
    ) {}

    public function interrupted(): bool
    {
        return $this->interruption !== null;
    }

    public function stopped(): bool
    {
        return $this->interruption === self::STOPPED;
    }

    public function failed(): bool
    {
        return $this->interruption === self::FAILED;
    }
}

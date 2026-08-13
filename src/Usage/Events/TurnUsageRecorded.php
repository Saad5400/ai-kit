<?php

namespace Saad\AiKit\Usage\Events;

use Saad\AiKit\Usage\UsageEvent;

/**
 * Fires after a turn's usage row is persisted — the hook downstream billing
 * (the credits module's meter) and app-side ledgers subscribe to. Failover
 * attempt rows never fire this event; only completed turns do.
 */
class TurnUsageRecorded
{
    public function __construct(public UsageEvent $usage) {}
}

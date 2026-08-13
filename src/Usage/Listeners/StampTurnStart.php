<?php

namespace Saad\AiKit\Usage\Listeners;

use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Support\TurnContext;

/**
 * Stamps the turn's wall-clock start so RecordTurnUsage can compute the
 * duration. Failover re-attempts re-dispatch the event with the same
 * invocation id and the stamp is set only once, so the duration covers the
 * whole turn including failed attempts.
 */
class StampTurnStart
{
    public function handle(PromptingAgent|StreamingAgent $event): void
    {
        TurnContext::stampStart($event->invocationId);
    }
}

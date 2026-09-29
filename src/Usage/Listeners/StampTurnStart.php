<?php

namespace Saad\AiKit\Usage\Listeners;

use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\ActiveRuns;

/**
 * Stamps the turn's wall-clock start so RecordTurnUsage can compute the
 * duration. Failover re-attempts re-dispatch the event with the same
 * invocation id and the stamp is set only once, so the duration covers the
 * whole turn including failed attempts. The prompt is remembered too, for
 * the usage row of a run that is later stopped ({@see ActiveRuns}).
 */
class StampTurnStart
{
    public function __construct(protected ActiveRuns $runs) {}

    public function handle(PromptingAgent|StreamingAgent $event): void
    {
        TurnContext::stampStart($event->invocationId);

        $this->runs->remember($event->invocationId, $event->prompt);
    }
}

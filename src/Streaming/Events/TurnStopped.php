<?php

namespace Saad\AiKit\Streaming\Events;

use Saad\AiKit\Streaming\TurnCancelledException;
use Saad\AiKit\Streaming\TurnRunner;

/**
 * The user stopped a streamed run and {@see TurnRunner}
 * settled it. laravel/ai dispatches no `AgentFailed` for a stop: the
 * {@see TurnCancelledException} thrown in lands at the
 * response's own iterator (running its catch callbacks — how the stop is
 * stored), never inside the provider loop that reports failures. The usage
 * module records the stopped turn's row from this.
 */
class TurnStopped
{
    public function __construct(public string $invocationId) {}
}

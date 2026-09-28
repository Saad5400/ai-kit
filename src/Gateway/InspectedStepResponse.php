<?php

namespace Saad\AiKit\Gateway;

use Laravel\Ai\Gateway\StepResponse;

/**
 * A step response that also carries what the gateway saw while parsing it:
 * whether tool-call markup leaked into the text (and the raw block that was
 * stripped, for salvage) and which upstream provider OpenRouter routed the
 * request to (so a retry can exclude it). The SDK loop only ever sees the
 * StepResponse contract; these fields are read by the gateway's own step
 * guard before the response leaves it.
 */
class InspectedStepResponse extends StepResponse
{
    public bool $markupLeaked = false;

    public string $leakedMarkup = '';

    public ?string $providerName = null;

    /**
     * Re-wrap a stock step with every field intact (the raw HTTP response
     * included), ready for {@see inspected()}.
     */
    public static function from(StepResponse $step): self
    {
        $inspected = new self(
            text: $step->text,
            toolCalls: $step->toolCalls,
            finishReason: $step->finishReason,
            usage: $step->usage,
            meta: $step->meta,
            structured: $step->structured,
            continuationToken: $step->continuationToken,
            replayBlocks: $step->replayBlocks,
            pendingApprovals: $step->pendingApprovals,
            reasoning: $step->reasoning,
            providerToolCalls: $step->providerToolCalls,
        );

        $inspected->raw = $step->raw;

        return $inspected;
    }

    public function inspected(bool $markupLeaked, string $leakedMarkup, ?string $providerName): static
    {
        $this->markupLeaked = $markupLeaked;
        $this->leakedMarkup = $leakedMarkup;
        $this->providerName = $providerName;

        return $this;
    }
}

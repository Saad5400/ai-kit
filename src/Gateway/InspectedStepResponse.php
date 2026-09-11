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

    public function inspected(bool $markupLeaked, string $leakedMarkup, ?string $providerName): static
    {
        $this->markupLeaked = $markupLeaked;
        $this->leakedMarkup = $leakedMarkup;
        $this->providerName = $providerName;

        return $this;
    }
}

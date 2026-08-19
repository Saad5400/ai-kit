<?php

namespace Saad\AiKit\Catalog;

/**
 * The catalog's routing declarations, as OpenRouter request-body fields.
 *
 * This replaces the kit's client-side fallback chains. Those built a
 * provider => model map for laravel/ai's native failover, which meant a
 * cloned provider entry per chain position, a retry loop in our process, and
 * a chain that could only move on the errors our own gateway saw and
 * classified. OpenRouter takes the same list as a `models` array and does the
 * failover upstream: it triggers on provider downtime, rate limits, moderation
 * refusals AND context-length overflow (the last of which a client-side loop
 * cannot detect without first paying for the rejection), and the request is
 * priced by the model that actually answered.
 *
 * The declaration site does not move — apps still write `fallbacks` and
 * `provider_max_price` on catalog entries. Only the mechanism does: the
 * gateway injects these fields into the request body instead of the kit
 * looping.
 *
 * @see https://openrouter.ai/docs/features/model-routing
 */
class ModelRouting
{
    public function __construct(protected CatalogSource $catalog) {}

    /**
     * The routing fields for a model, or an empty array when its catalog
     * entry declares neither a chain nor a price cap (and for models the
     * catalog has never heard of — an app may prompt outside it).
     *
     * The model itself leads the `models` array: OpenRouter tries the list in
     * order, so omitting it would skip straight to the first fallback.
     *
     * @return array{models?: list<string>, provider?: array<string, mixed>}
     */
    public function requestFields(string $modelId): array
    {
        $definition = $this->catalog->find($modelId);

        if ($definition === null) {
            return [];
        }

        $fields = [];

        if ($definition->fallbacks !== []) {
            $fields['models'] = [$modelId, ...$definition->fallbacks];
        }

        if ($definition->providerMaxPrice) {
            // OpenRouter's own routing cap, not a local filter: with a chain
            // in play the model that answers may not be the one we priced, so
            // the ceiling has to travel with the request.
            $fields['provider'] = ['max_price' => $definition->providerMaxPrice];
        }

        return $fields;
    }
}

<?php

namespace Saad\AiKit\Catalog;

/**
 * Translates a model's declared fallbacks into the provider => model list
 * laravel/ai's native failover understands. The SDK keys the failover list
 * by provider name, so one provider entry can never carry two models — but
 * `Ai::extend()` keys custom creators by *driver*, so alias entries that
 * clone the base provider's config (registered by CatalogServiceProvider)
 * resolve through the same gateway and let a chain stay on one upstream:
 *
 *     ['openrouter' => 'model-a', 'openrouter--fallback-1' => 'model-b']
 *
 * Failover triggers on FailoverableException (429, 402, overloaded statuses,
 * or an open circuit) after the gateway's own retries are exhausted.
 */
class FallbackChains
{
    public function __construct(
        protected CatalogSource $catalog,
        protected string $provider,
    ) {}

    /**
     * The failover list for a model: itself first, then declared fallbacks.
     * Pass the result as the `provider` argument when prompting an agent, or
     * return it from the agent's provider() method.
     *
     * @return array<string, string>
     */
    public function providersFor(string $modelId): array
    {
        $chain = [$this->provider => $modelId];

        foreach ($this->catalog->find($modelId)?->fallbacks ?? [] as $index => $fallback) {
            $chain[static::alias($this->provider, $index)] = $fallback;
        }

        return $chain;
    }

    public static function alias(string $provider, int $index): string
    {
        return "{$provider}--fallback-".($index + 1);
    }

    /**
     * How many alias provider entries the catalog needs — the longest
     * declared fallback list.
     */
    public function aliasCount(): int
    {
        return (int) $this->catalog->models()
            ->map(fn (ModelDefinition $model): int => count($model->fallbacks))
            ->max();
    }
}

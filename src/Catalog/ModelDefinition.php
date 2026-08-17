<?php

namespace Saad\AiKit\Catalog;

use Laravel\Ai\Responses\Data\Usage;

/**
 * One model the app may route turns to. `id` is the provider-facing model
 * string (e.g. "google/gemini-3.5-flash" on OpenRouter). Prices are USD per
 * one million tokens and may be null when unknown — metering then relies on
 * the provider-reported cost instead of estimating. `fallbacks` lists model
 * ids to fail over to, in declared order, when this model is rate limited,
 * overloaded, or its circuit is open. Chains are explicit, never transitive:
 * a fallback's own fallbacks are not followed.
 *
 * `capabilities` describe what the model can do (tools, vision, reasoning);
 * `tasks` are the app's routing labels (chat, mcq, summary) — a task menu the
 * model is offered for, selected through {@see Catalog}. `tags` are routing
 * markers, of which `recommended` is the one the kit knows about: the sync
 * command enforces exactly one recommended model per declared task.
 * `providerMaxPrice` (e.g. {prompt, completion}) rides into the provider's
 * routing options so a routed pool can never exceed the declared rate.
 */
final class ModelDefinition
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $tasks
     * @param  list<string>  $tags
     * @param  list<string>  $fallbacks
     * @param  array<string, mixed>|null  $providerMaxPrice
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $label = null,
        public readonly ?float $inputUsdPerMillion = null,
        public readonly ?float $outputUsdPerMillion = null,
        public readonly ?int $contextLength = null,
        public readonly array $capabilities = [],
        public readonly array $tasks = [],
        public readonly array $tags = [],
        public readonly array $fallbacks = [],
        public readonly ?array $providerMaxPrice = null,
        public readonly array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id: $id,
            label: $data['label'] ?? null,
            inputUsdPerMillion: isset($data['input_usd_per_million']) ? (float) $data['input_usd_per_million'] : null,
            outputUsdPerMillion: isset($data['output_usd_per_million']) ? (float) $data['output_usd_per_million'] : null,
            contextLength: isset($data['context_length']) ? (int) $data['context_length'] : null,
            capabilities: array_values($data['capabilities'] ?? []),
            tasks: array_values($data['tasks'] ?? []),
            tags: array_values($data['tags'] ?? []),
            fallbacks: array_values($data['fallbacks'] ?? []),
            providerMaxPrice: $data['provider_max_price'] ?? null,
            extra: $data['extra'] ?? [],
        );
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function offersTask(string $task): bool
    {
        return in_array($task, $this->tasks, true);
    }

    public function isTagged(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function isRecommended(): bool
    {
        return $this->isTagged('recommended');
    }

    /**
     * Estimate the USD cost of the given usage from declared prices, or null
     * when either price is missing. OpenRouter counts reasoning tokens inside
     * completion_tokens, so only prompt and completion enter the estimate.
     */
    public function estimatedCostUsd(Usage $usage): ?float
    {
        if ($this->inputUsdPerMillion === null || $this->outputUsdPerMillion === null) {
            return null;
        }

        return ($usage->promptTokens * $this->inputUsdPerMillion
            + $usage->completionTokens * $this->outputUsdPerMillion) / 1_000_000;
    }
}

<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the database-backed catalog, kept in sync from the config
 * catalog by `ai-kit:sync-models`. `key` is the public identifier apps route
 * by (and the {@see ModelDefinition} id); `provider`/`provider_model_id` are
 * the swap seam for rows whose provider-facing slug differs from the key.
 * Disabled rows are retained for ops history but are invisible to the
 * {@see DatabaseCatalogSource}. `meta` is the app's bag (display names,
 * tiers, badge figures) — the kit never reads it.
 *
 * @property int $id
 * @property string $key
 * @property string|null $provider
 * @property string|null $provider_model_id
 * @property string|null $label
 * @property float|null $input_usd_per_million
 * @property float|null $output_usd_per_million
 * @property int|null $context_length
 * @property list<string> $capabilities
 * @property list<string> $tasks
 * @property list<string> $tags
 * @property list<string> $fallbacks
 * @property array<string, mixed>|null $provider_max_price
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, mixed> $meta
 */
class AiModel extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-kit.catalog.table', 'ai_models');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_usd_per_million' => 'float',
            'output_usd_per_million' => 'float',
            'context_length' => 'integer',
            'capabilities' => 'array',
            'tasks' => 'array',
            'tags' => 'array',
            'fallbacks' => 'array',
            'provider_max_price' => 'array',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort_order');
    }

    public function toDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            id: $this->key,
            label: $this->label,
            inputUsdPerMillion: $this->input_usd_per_million,
            outputUsdPerMillion: $this->output_usd_per_million,
            contextLength: $this->context_length,
            capabilities: array_values($this->capabilities ?? []),
            tasks: array_values($this->tasks ?? []),
            tags: array_values($this->tags ?? []),
            fallbacks: array_values($this->fallbacks ?? []),
            providerMaxPrice: $this->provider_max_price,
            extra: array_filter([
                'provider' => $this->provider,
                'provider_model_id' => $this->provider_model_id,
                'meta' => $this->meta ?: null,
            ], fn (mixed $value): bool => $value !== null),
        );
    }
}

<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;

/**
 * Reads the catalog from config on every call rather than capturing it at
 * construction: the service provider's own boot resolves this singleton (to
 * count fallback aliases), which would otherwise freeze the catalog before
 * the app's configuration is final.
 */
class ConfigCatalogSource implements CatalogSource
{
    public function __construct(
        protected Repository $config,
        protected string $key = 'ai-kit.catalog.models',
    ) {}

    public function models(): Collection
    {
        return (new Collection($this->definitions()))
            ->map(fn (array $data, string $id): ModelDefinition => ModelDefinition::fromArray($id, $data))
            ->values();
    }

    public function find(string $modelId): ?ModelDefinition
    {
        $definitions = $this->definitions();

        return isset($definitions[$modelId])
            ? ModelDefinition::fromArray($modelId, $definitions[$modelId])
            : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function definitions(): array
    {
        return $this->config->get($this->key, []);
    }
}

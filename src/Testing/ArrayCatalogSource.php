<?php

namespace Saad\AiKit\Testing;

use Illuminate\Support\Collection;
use Saad\AiKit\Catalog\CatalogSource;
use Saad\AiKit\Catalog\ModelDefinition;

/**
 * A {@see CatalogSource} over a plain array, for tests that need a known
 * catalog without touching config or a database. Accepts ready-made
 * {@see ModelDefinition}s or config-shaped arrays keyed by model id.
 */
class ArrayCatalogSource implements CatalogSource
{
    /** @var array<string, ModelDefinition> */
    protected array $models = [];

    /**
     * @param  iterable<int|string, ModelDefinition|array<string, mixed>>  $models
     */
    public function __construct(iterable $models = [])
    {
        foreach ($models as $key => $model) {
            $this->add($model instanceof ModelDefinition
                ? $model
                : ModelDefinition::fromArray((string) $key, $model));
        }
    }

    public function add(ModelDefinition $model): static
    {
        $this->models[$model->id] = $model;

        return $this;
    }

    public function models(): Collection
    {
        return new Collection(array_values($this->models));
    }

    public function find(string $modelId): ?ModelDefinition
    {
        return $this->models[$modelId] ?? null;
    }
}

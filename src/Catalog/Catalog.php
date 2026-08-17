<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Support\Collection;

/**
 * Task-routing helpers over whichever {@see CatalogSource} is bound.
 * Catalogs are small (around a dozen rows), so filtering happens in PHP —
 * no LIKE-on-json narrowing, no database-specific containment predicate.
 *
 * The composition apps use for model resolution: a named key first, then
 * `recommendedFor($task)`, then `forTask($task)->first()` — and treat a
 * null as "no model available" (a 503), never a doomed dispatch.
 */
class Catalog
{
    public function __construct(protected CatalogSource $source) {}

    /**
     * @return Collection<int, ModelDefinition>
     */
    public function models(): Collection
    {
        return $this->source->models();
    }

    public function find(string $modelId): ?ModelDefinition
    {
        return $this->source->find($modelId);
    }

    /**
     * The models offered for a task, in source order.
     *
     * @return Collection<int, ModelDefinition>
     */
    public function forTask(string $task): Collection
    {
        return $this->models()
            ->filter(fn (ModelDefinition $model): bool => $model->offersTask($task))
            ->values();
    }

    /**
     * The one recommended model for a task — the sync command's invariant
     * guarantees at most one; should data drift live, the first in source
     * order wins.
     */
    public function recommendedFor(string $task): ?ModelDefinition
    {
        return $this->forTask($task)
            ->first(fn (ModelDefinition $model): bool => $model->isRecommended());
    }
}

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
     * The fleet's default chat model id (DECISIONS.md #21) — what an app
     * dispatches a chat turn to when nothing more specific applies.
     *
     * Resolution stays the app's: an app-level setting (uqucc keeps one in the
     * database) wins over config, and config wins over nothing. This is the
     * floor of that chain, shared so a model decision lands in one place
     * instead of three, and it is deliberately a SLUG rather than a
     * {@see ModelDefinition} — an app may prompt a model its catalog never
     * declared, and `find()` is right there for the entry when it exists.
     */
    public function chatModel(): string
    {
        return (string) config('ai-kit.chat.model', 'google/gemini-3.5-flash-lite');
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

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
     * The fleet's default chat model id (DECISIONS.md #26) — what an app
     * dispatches a chat turn to when nothing more specific applies.
     *
     * Config is the WHOLE chain now. #26 retired the app-level database
     * override that used to sit above this (uqucc's `AiSettings->chat_model`):
     * a row that silently beat config meant a config change could look applied
     * and change nothing, which is exactly how the fleet spent days answering
     * students on the wrong model. An app that genuinely needs a different
     * model sets AI_KIT_CHAT_MODEL or overrides the key in its own config.
     *
     * Deliberately a SLUG rather than a {@see ModelDefinition} — an app may
     * prompt a model its catalog never declared, and `find()` is right there
     * for the entry when it exists.
     */
    public function chatModel(): string
    {
        return (string) config('ai-kit.chat.model', 'deepseek/deepseek-v4-flash');
    }

    /**
     * The reasoning effort the fleet's chat turns ask for (DECISIONS.md #26).
     * Shared rather than per-app: how hard the assistant thinks is what makes
     * it usable for real students, not a per-app preference.
     */
    public function chatReasoningEffort(): string
    {
        return (string) config('ai-kit.chat.reasoning_effort', 'medium');
    }

    /**
     * The fleet's default VISION model id (DECISIONS.md #26b) — the model an
     * app sends images to.
     *
     * Separate from {@see chatModel()} by necessity, not taste: the pinned
     * chat model is text-only on OpenRouter, so routing an image at it fails
     * rather than degrades.
     */
    public function visionModel(): string
    {
        return (string) config('ai-kit.vision.model', 'google/gemini-2.5-flash-lite');
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

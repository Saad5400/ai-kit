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
        return (string) config('ai-kit.chat.model', 'deepseek/deepseek-v4-flash-0731');
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
        return (string) config('ai-kit.vision.model', 'google/gemini-3.1-flash-lite');
    }

    /**
     * The sturdier model a vision caller retries onto when {@see visionModel()}
     * errors, or returns unusable output twice in a row (DECISIONS.md #28a).
     */
    public function visionFallbackModel(): string
    {
        return (string) config('ai-kit.vision.fallback_model', 'google/gemini-3.5-flash-lite');
    }

    /**
     * The fleet's default model for work read straight FROM A FILE — a scanned
     * PDF parsed natively, a lecture recording transcribed, a long document
     * summarized or translated whole (DECISIONS.md #28a).
     *
     * A third decision beside {@see chatModel()} and {@see visionModel()}
     * because it carries a hard capability floor the others do not: the model
     * must accept `file` AND `audio` input.
     */
    public function documentsModel(): string
    {
        return (string) config('ai-kit.documents.model', 'google/gemini-3.1-flash-lite');
    }

    /**
     * The sturdier model a documents caller retries onto — see
     * {@see documentsModel()}. Cheaper than the primary on AUDIO, so an ASR
     * retry never costs more than the attempt it replaces.
     */
    public function documentsFallbackModel(): string
    {
        return (string) config('ai-kit.documents.fallback_model', 'google/gemini-3.5-flash-lite');
    }

    /**
     * The heavyweight model for admin-triggered, review-gated drafting — page
     * copy from a source document, proposed revisions, a course report's prose
     * (DECISIONS.md #28a). Rare and never on a student's latency budget, so it
     * buys reasoning depth the chat default does not need.
     */
    public function authoringModel(): string
    {
        return (string) config('ai-kit.authoring.model', 'deepseek/deepseek-v4-pro-0813');
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

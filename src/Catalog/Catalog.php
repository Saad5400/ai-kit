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
     * The model that writes a recording's WORDS — a free-text verbatim
     * transcript, dialect kept and English terms in Latin script, with no
     * timestamps (DECISIONS.md #30). Must accept `audio` input.
     */
    public function transcriptionModel(): string
    {
        return (string) config('ai-kit.transcription.model', 'google/gemini-3.8-flash');
    }

    /** The model a transcription caller retries onto — see {@see transcriptionModel()}. */
    public function transcriptionFallbackModel(): string
    {
        return (string) config('ai-kit.transcription.fallback_model', 'google/gemini-3.1-flash-lite');
    }

    /**
     * The speech-to-text model that supplies a recording's CLOCK: word-level
     * timestamps from OpenRouter's `/audio/transcriptions` endpoint
     * (`response_format: verbose_json`, `timestamp_granularities: [word]`),
     * onto which the caller aligns the words of {@see transcriptionModel()}
     * (DECISIONS.md #30). A speech-to-text slug, not a chat model, so it is
     * not a catalog entry.
     */
    public function transcriptionTimingModel(): string
    {
        return (string) config('ai-kit.transcription.timing_model', 'elevenlabs/scribe-v2');
    }

    /** The timing model a caller retries onto — see {@see transcriptionTimingModel()}. */
    public function transcriptionTimingFallbackModel(): string
    {
        return (string) config('ai-kit.transcription.timing_fallback_model', 'deepgram/nova-3');
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
     * The fleet's EMBEDDING model (DECISIONS.md #29) — the slug the
     * `embeddings` lane's driver sends, from `catalog.retrieval_models`, never
     * from the chat menu. Read the vectors through the bound
     * `Saad\AiKit\Rag\Embeddings\Embedder` rather than calling a provider.
     */
    public function embeddingsModel(): string
    {
        return (string) config('ai-kit.embeddings.model', 'openai/text-embedding-3-small');
    }

    /**
     * The vector length the `embeddings` lane produces — index DDL in every
     * consuming app (`vector(N)`), so a change means re-embedding.
     */
    public function embeddingsDimensions(): int
    {
        return (int) config('ai-kit.embeddings.dimensions', 1536);
    }

    /**
     * The fleet's RERANK model (DECISIONS.md #29). Having a lane does
     * not switch reranking on: the app decides when to call the bound
     * `Saad\AiKit\Rag\Rerank\Reranker`.
     */
    public function rerankModel(): string
    {
        return (string) config('ai-kit.rerank.model', 'cohere/rerank-v3.5');
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

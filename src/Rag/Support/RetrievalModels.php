<?php

namespace Saad\AiKit\Rag\Support;

/**
 * The retrieval-model registry, `ai-kit.catalog.retrieval_models` — the
 * embedding and rerank slugs the `embeddings` / `rerank` lanes may point at,
 * with the facts the drivers need (native dimensions, whether the model is
 * Matryoshka-trained so a prefix of its vector is itself a valid embedding,
 * whether the provider honours the `dimensions` request field, the query
 * instruction an instruction-tuned embedder expects).
 *
 * Deliberately NOT part of `catalog.models`: that list is the user-facing
 * chat menu (priced as ×N of the default, synced into apps' `ai_models`
 * tables, one `recommended` per task). A rerank model is not something a
 * student picks, and putting one there would surface it in every picker.
 */
final class RetrievalModels
{
    /**
     * The registry entry for a slug, or null when the kit has never heard of it.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $model): ?array
    {
        // Read the whole map: slugs contain dots (`cohere/rerank-v3.5`), which
        // a dotted config() lookup would split on.
        $entry = ((array) config('ai-kit.catalog.retrieval_models', []))[$model] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Every registered slug of one kind (`embeddings` or `rerank`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ofKind(string $kind): array
    {
        return array_filter(
            (array) config('ai-kit.catalog.retrieval_models', []),
            fn ($entry): bool => is_array($entry) && ($entry['kind'] ?? null) === $kind,
        );
    }
}

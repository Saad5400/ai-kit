<?php

namespace Saad\AiKit\Rag\Embeddings;

/**
 * An embedding backend. Callers depend on this contract and never on a
 * provider: the kit binds the `embeddings` lane's driver to it, a test binds
 * {@see FakeEmbedder}, an app with its own backend binds its own class.
 *
 * Documents and queries are separate calls because instruction-tuned
 * embedders (Qwen3, E5, …) embed a QUERY with an instruction prefix and a
 * document without one; for a symmetric model the two are identical.
 */
interface Embedder
{
    /**
     * Embed texts to be stored and searched (chunks, titles), index-aligned
     * with the input.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one vector of {@see dimensions()} floats per text
     */
    public function embedDocuments(array $texts): array;

    /**
     * Embed search queries, index-aligned with the input — the lane's query
     * instruction, when there is one, is applied here.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedQueries(array $texts): array;

    /** The vector length every returned embedding has. */
    public function dimensions(): int;

    /** The model slug the vectors come from. */
    public function model(): string;

    /**
     * What makes two vectors comparable: `{model}:{dimensions}`, plus a short
     * hash of the query instruction when one is applied. Key any cache of
     * QUERY vectors on it, and record it beside stored ones — a vector from
     * another geometry is silently meaningless against them.
     */
    public function geometry(): string;
}

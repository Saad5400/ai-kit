<?php

namespace Saad\AiKit\Rag\Embeddings;

/**
 * Deterministic, offline embedder for tests and hermetic evals.
 *
 * Same text + same dimensions → the same unit vector, every run: each
 * whitespace token adds weight to two hash-chosen dimensions (1.0 and 0.5),
 * then the vector is L2-normalised. Shared tokens therefore mean higher
 * cosine — enough to exercise a semantic ranking path without a model.
 *
 * Ported verbatim from catodemy's `App\Services\Corpus\Embedders\FakeEmbedder`
 * (origin/main fc11c288) so an app that switches to this class gets
 * bit-identical vectors and its existing ranking expectations hold. Queries
 * embed exactly like documents (a fake has no instruction to apply).
 */
class FakeEmbedder implements Embedder
{
    public function __construct(
        private readonly int $dimensions = 1536,
        private readonly string $model = 'fake',
    ) {}

    public function embedDocuments(array $texts): array
    {
        return array_map(fn (string $text): array => $this->vectorFor($text), array_values($texts));
    }

    public function embedQueries(array $texts): array
    {
        return $this->embedDocuments($texts);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function geometry(): string
    {
        return $this->model.':'.$this->dimensions;
    }

    /** @return list<float> */
    private function vectorFor(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        $tokens = preg_split('/\s+/u', mb_strtolower(trim($text)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $vector[crc32($token) % $this->dimensions] += 1.0;
            $vector[crc32('salt:'.$token) % $this->dimensions] += 0.5;
        }

        return Vectors::normalize($vector);
    }
}

<?php

namespace Saad\AiKit\Rag\Rerank;

use Closure;
use Saad\AiKit\Rag\Text\ArabicNormalizer;

/**
 * Deterministic, offline reranker for tests and hermetic evals.
 *
 * By default a document scores the share of the query's normalised terms it
 * contains (so «محاضره» in the query matches «المحاضرة» only if the folded
 * term appears) — ordering is stable and assertable. Pass a scorer to control
 * scores exactly, or {@see failing()} to exercise a caller's fallback path.
 * Every request is recorded in {@see $requests}.
 */
class FakeReranker implements Reranker
{
    /** @var list<RerankRequest> */
    public array $requests = [];

    /**
     * @param  (Closure(string $query, string $document, int $index): float)|null  $scorer
     */
    public function __construct(
        private readonly ?Closure $scorer = null,
        private readonly ?string $failure = null,
        private readonly string $model = 'fake',
    ) {}

    /** A fake whose every request fails with `$reason` (e.g. `timeout`). */
    public static function failing(string $reason = 'timeout'): self
    {
        return new self(failure: $reason);
    }

    public function rerank(string $query, array $documents, ?int $topN = null): array
    {
        return $this->rerankMany([new RerankRequest($query, array_values($documents), $topN)])[0]->scoresOrFail();
    }

    public function rerankMany(array $requests): array
    {
        $responses = [];

        foreach ($requests as $request) {
            $this->requests[] = $request;

            if ($this->failure !== null) {
                $responses[] = RerankResponse::failed($this->failure);

                continue;
            }

            $scores = [];

            foreach (array_values($request->documents) as $index => $document) {
                $scores[] = new RerankScore($index, $this->score($request->query, $document, $index));
            }

            $scores = RerankScore::sort($scores);

            $responses[] = new RerankResponse(
                $request->topN === null ? $scores : array_slice($scores, 0, max(0, $request->topN)),
            );
        }

        return $responses;
    }

    public function model(): string
    {
        return $this->model;
    }

    private function score(string $query, string $document, int $index): float
    {
        if ($this->scorer !== null) {
            return ($this->scorer)($query, $document, $index);
        }

        $terms = array_unique(array_map('mb_strtolower', ArabicNormalizer::tokens($query)));

        if ($terms === []) {
            return 0.0;
        }

        $haystack = ArabicNormalizer::normalizeLower($document);
        $found = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $found++;
            }
        }

        return $found / count($terms);
    }
}

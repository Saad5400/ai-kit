<?php

namespace Saad\AiKit\Rag\Rerank;

/**
 * The `null` driver: no model, no network, no cost. Hands the documents back
 * in the order they came in (scores 1 → 0 by position), so a pipeline that
 * always calls a reranker behaves exactly as if the stage were absent.
 */
class NullReranker implements Reranker
{
    public function rerank(string $query, array $documents, ?int $topN = null): array
    {
        $count = count($documents);
        $scores = [];

        foreach (array_keys(array_values($documents)) as $index) {
            $scores[] = new RerankScore($index, 1.0 - $index / max(1, $count));
        }

        return $topN === null ? $scores : array_slice($scores, 0, max(0, $topN));
    }

    public function rerankMany(array $requests): array
    {
        return array_map(
            fn (RerankRequest $request): RerankResponse => new RerankResponse($this->rerank($request->query, $request->documents, $request->topN)),
            array_values($requests),
        );
    }

    public function model(): string
    {
        return 'none';
    }
}

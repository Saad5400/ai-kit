<?php

namespace Saad\AiKit\Rag\Rerank;

/** One reranked document: its index in the request's documents and its relevance score. */
final class RerankScore
{
    public function __construct(
        public readonly int $index,
        public readonly float $score,
    ) {}

    /**
     * Best first; equal scores keep the incoming order (index ascending), so
     * a reranker that cannot tell two candidates apart leaves them as fused.
     *
     * @param  list<RerankScore>  $scores
     * @return list<RerankScore>
     */
    public static function sort(array $scores): array
    {
        usort($scores, static fn (self $a, self $b): int => ($b->score <=> $a->score) ?: ($a->index <=> $b->index));

        return $scores;
    }
}

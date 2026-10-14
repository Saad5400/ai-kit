<?php

namespace Saad\AiKit\Rag\Rerank;

/**
 * A cross-encoder reranker: reads the query and each candidate TOGETHER and
 * scores how well the candidate answers it. Callers depend on this contract;
 * the kit binds the `rerank` lane's driver, a test binds {@see FakeReranker}.
 *
 * Scores are comparable only within one model — never across models, so any
 * threshold on them (a confidence band) is per model.
 */
interface Reranker
{
    /**
     * Score `$documents` against `$query`, best first. Each {@see RerankScore}
     * names its document by INDEX into `$documents`; with `$topN` only the best
     * N come back.
     *
     * @param  list<string>  $documents
     * @return list<RerankScore>
     *
     * @throws RerankFailed on timeout, HTTP failure or an unreadable response —
     *                      the caller falls back to its own order, loudly
     */
    public function rerank(string $query, array $documents, ?int $topN = null): array;

    /**
     * Several independent reranks at once (concurrently where the driver
     * can), index-aligned with `$requests`. One request failing never fails
     * the others: its {@see RerankResponse} carries the failure instead.
     *
     * @param  list<RerankRequest>  $requests
     * @return list<RerankResponse>
     */
    public function rerankMany(array $requests): array;

    /** The model slug the scores come from (confidence bands key on it). */
    public function model(): string;
}

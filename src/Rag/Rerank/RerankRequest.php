<?php

namespace Saad\AiKit\Rag\Rerank;

/** One query and its candidate documents, for {@see Reranker::rerankMany()}. */
final class RerankRequest
{
    /**
     * @param  list<string>  $documents
     */
    public function __construct(
        public readonly string $query,
        public readonly array $documents,
        public readonly ?int $topN = null,
    ) {}
}

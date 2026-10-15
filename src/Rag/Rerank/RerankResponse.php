<?php

namespace Saad\AiKit\Rag\Rerank;

/**
 * The outcome of one request inside {@see Reranker::rerankMany()}: its scores,
 * or why there are none: `disabled` / `budget` (the {@see GuardedReranker}
 * policy), `timeout`, `http_<status>`, `connection`, `bad_response`,
 * `no_api_key`. Any failure means "keep your own order".
 */
final class RerankResponse
{
    /**
     * @param  list<RerankScore>  $scores  best first
     */
    public function __construct(
        public readonly array $scores = [],
        public readonly ?string $failure = null,
    ) {}

    public static function failed(string $reason): self
    {
        return new self([], $reason);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }

    /**
     * The scores, or the failure as an exception.
     *
     * @return list<RerankScore>
     *
     * @throws RerankFailed
     */
    public function scoresOrFail(): array
    {
        if ($this->failure !== null) {
            throw new RerankFailed($this->failure);
        }

        return $this->scores;
    }
}

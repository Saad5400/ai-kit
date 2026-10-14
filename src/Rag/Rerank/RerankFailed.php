<?php

namespace Saad\AiKit\Rag\Rerank;

use RuntimeException;

/**
 * A rerank that produced no scores. `$reason` is short and machine-readable
 * (`timeout`, `http_429`, `connection`, `bad_response`) so the caller can put
 * it straight into its diagnostics.
 */
class RerankFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Rerank failed: {$reason}");
    }
}

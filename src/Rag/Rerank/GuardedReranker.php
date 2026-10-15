<?php

namespace Saad\AiKit\Rag\Rerank;

use Illuminate\Support\Facades\Log;
use Saad\AiKit\Rag\Support\RetrievalModels;
use Saad\AiKit\Safety\BudgetGuard;

/**
 * The policy wrapper the `rag` module puts around every bound rerank driver
 * (DECISIONS.md #29). It never fails a search; a request it will not send
 * comes back as a failed {@see RerankResponse} — the caller keeps its fused
 * order and reports the reason (e.g. `diagnostics.rerank`):
 *
 *  - `disabled` — `ai-kit.rerank.enabled` is off. OFF by default: an app turns
 *    reranking on only once its own eval shows a gain (#29a).
 *  - `budget` — the daily BudgetGuard is exhausted, or this one search (one
 *    {@see RerankRequest}: one query and its candidates; a bulk search of N
 *    queries is N searches) is estimated above `max_usd_per_search` (#29c).
 *
 * The estimate is a GUARD, never a price (#26c — what is recorded is the
 * provider-reported cost): Cohere-style models are priced per search unit
 * (one query + up to 100 documents, each split into ~500-token pieces),
 * token-priced models per million tokens (query × documents + documents),
 * from `catalog.retrieval_models`, with ~3 characters per token. A model
 * with no registered price is not capped. A call whose REPORTED cost still
 * exceeded the ceiling is logged, so a stale price shows up.
 */
class GuardedReranker implements Reranker
{
    /** Cohere bills a document longer than this many tokens (query included) as several. */
    private const UNIT_TOKENS = 500;

    /** Documents per Cohere search unit. */
    private const UNIT_DOCUMENTS = 100;

    public function __construct(
        private readonly Reranker $driver,
        private readonly bool $enabled,
        private readonly ?float $maxUsdPerSearch = null,
        private readonly ?BudgetGuard $budget = null,
    ) {}

    public function rerank(string $query, array $documents, ?int $topN = null): array
    {
        return $this->rerankMany([new RerankRequest($query, array_values($documents), $topN)])[0]->scoresOrFail();
    }

    public function rerankMany(array $requests): array
    {
        $requests = array_values($requests);

        if (! $this->enabled) {
            return array_map(static fn (): RerankResponse => RerankResponse::failed('disabled'), $requests);
        }

        if ($this->budget?->exceeded() === true) {
            return array_map(static fn (): RerankResponse => RerankResponse::failed('budget'), $requests);
        }

        $responses = [];
        $send = [];

        foreach ($requests as $i => $request) {
            $estimate = $this->estimateUsd($request);

            if ($this->maxUsdPerSearch !== null && $estimate !== null && $estimate > $this->maxUsdPerSearch) {
                $responses[$i] = RerankResponse::failed('budget');
            } else {
                $send[$i] = $request;
            }
        }

        if ($send !== []) {
            foreach (array_values($this->driver->rerankMany(array_values($send))) as $position => $response) {
                $responses[array_keys($send)[$position]] = $response;
            }
        }

        ksort($responses);

        return array_values($responses);
    }

    public function model(): string
    {
        return $this->driver->model();
    }

    /** The driver this guard wraps. */
    public function driver(): Reranker
    {
        return $this->driver;
    }

    /**
     * What this one request is expected to cost, or null when the model has
     * no registered price.
     */
    public function estimateUsd(RerankRequest $request): ?float
    {
        $entry = RetrievalModels::find($this->driver->model()) ?? [];
        $queryTokens = self::tokens($request->query);
        $documentTokens = array_map(self::tokens(...), array_values($request->documents));

        if (isset($entry['usd_per_thousand_searches'])) {
            $pieces = 0;

            foreach ($documentTokens as $tokens) {
                $pieces += max(1, (int) ceil(($queryTokens + $tokens) / self::UNIT_TOKENS));
            }

            $units = max(1, (int) ceil($pieces / self::UNIT_DOCUMENTS));

            return $units * (float) $entry['usd_per_thousand_searches'] / 1000;
        }

        if (isset($entry['usd_per_million_tokens'])) {
            $tokens = $queryTokens * count($documentTokens) + array_sum($documentTokens);

            return $tokens * (float) $entry['usd_per_million_tokens'] / 1_000_000;
        }

        return null;
    }

    /** Called by the `rag` module when a rerank reported its cost; flags a ceiling that did not hold. */
    public static function auditReportedCost(string $model, int $requests, ?float $costUsd): void
    {
        $ceiling = config('ai-kit.rerank.max_usd_per_search');

        if ($costUsd === null || ! is_numeric($ceiling) || $requests < 1 || $costUsd / $requests <= (float) $ceiling) {
            return;
        }

        Log::warning('ai-kit: a rerank cost more per search than ai-kit.rerank.max_usd_per_search — check the model\'s registered price.', [
            'model' => $model,
            'cost_usd' => $costUsd,
            'searches' => $requests,
            'max_usd_per_search' => (float) $ceiling,
        ]);
    }

    private static function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }
}

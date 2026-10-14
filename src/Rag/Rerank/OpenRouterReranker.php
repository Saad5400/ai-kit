<?php

namespace Saad\AiKit\Rag\Rerank;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Rag\Support\OpenRouterEndpoint;
use Throwable;

/**
 * The `rerank` lane over OpenRouter's `POST {base}/rerank` (Cohere-style body:
 * `{model, query, documents, top_n}` → `results[{index, relevance_score}]`).
 * Measured working 2026-10-15 for `cohere/rerank-v3.5` and
 * `cohere/rerank-4-fast` on an Arabic/English pair.
 *
 * Built for the hot path: ONE attempt per request (no retries — a retry
 * would blow the latency budget it exists to protect), a hard total timeout
 * of `timeout_ms`, and {@see rerankMany()} sends every request concurrently.
 * A failure never throws out of `rerankMany()`; it comes back as a failed
 * {@see RerankResponse} so the caller falls back for that query alone.
 */
class OpenRouterReranker implements Reranker
{
    public function __construct(
        private readonly OpenRouterEndpoint $endpoint,
        private readonly Dispatcher $events,
        private readonly string $model,
        private readonly int $timeoutMs = 800,
        private readonly ?int $defaultTopN = null,
    ) {}

    /** Built from the `ai-kit.rerank` lane. */
    public static function fromConfig(): self
    {
        $config = (array) config('ai-kit.rerank', []);

        return new self(
            endpoint: OpenRouterEndpoint::make(),
            events: app(Dispatcher::class),
            model: (string) ($config['model'] ?? 'cohere/rerank-v3.5'),
            timeoutMs: max(1, (int) ($config['timeout_ms'] ?? 800)),
            defaultTopN: isset($config['top_n']) ? (int) $config['top_n'] : null,
        );
    }

    public function rerank(string $query, array $documents, ?int $topN = null): array
    {
        return $this->rerankMany([new RerankRequest($query, array_values($documents), $topN)])[0]->scoresOrFail();
    }

    public function rerankMany(array $requests): array
    {
        $requests = array_values($requests);
        $responses = [];
        $pending = [];

        foreach ($requests as $i => $request) {
            if ($request->documents === []) {
                $responses[$i] = new RerankResponse([]);
            } else {
                $pending[$i] = $request;
            }
        }

        if ($pending !== []) {
            try {
                $this->endpoint->key();
            } catch (RuntimeException) {
                foreach (array_keys($pending) as $i) {
                    $responses[$i] = RerankResponse::failed('no_api_key');
                }
                $pending = [];
            }
        }

        if ($pending !== []) {
            $url = $this->endpoint->url('rerank');
            $timeout = $this->timeoutMs / 1000;
            $started = hrtime(true);

            $results = $this->endpoint->request()->pool(function (Pool $pool) use ($pending, $url, $timeout): void {
                foreach ($pending as $i => $request) {
                    $this->endpoint->authorize($pool->as((string) $i))
                        ->timeout($timeout)
                        ->connectTimeout($timeout)
                        ->post($url, array_filter([
                            'model' => $this->model,
                            'query' => $request->query,
                            'documents' => array_values($request->documents),
                            'top_n' => $request->topN ?? $this->defaultTopN,
                        ], static fn ($value): bool => $value !== null));
                }
            });

            $elapsedMs = (int) round((hrtime(true) - $started) / 1e6);
            $tokens = 0;
            $cost = null;
            $failures = 0;

            foreach ($pending as $i => $request) {
                $result = $results[(string) $i] ?? null;
                $responses[$i] = $this->read($result, count($request->documents));

                if (! $responses[$i]->ok()) {
                    $failures++;
                }

                if ($result instanceof Response && $result->successful()) {
                    $tokens += (int) ($result->json('usage.total_tokens') ?? 0);
                    $one = OpenRouterEndpoint::cost($result->json('usage'));
                    $cost = $one === null ? $cost : ($cost ?? 0.0) + $one;
                }
            }

            $this->events->dispatch(new RetrievalSpend(
                lane: 'rerank',
                model: $this->model,
                requests: count($pending),
                inputs: array_sum(array_map(static fn (RerankRequest $r): int => count($r->documents), $pending)),
                tokens: $tokens,
                costUsd: $cost,
                durationMs: $elapsedMs,
                failed: $failures > 0,
            ));
        }

        ksort($responses);

        return array_values($responses);
    }

    public function model(): string
    {
        return $this->model;
    }

    private function read(mixed $result, int $documentCount): RerankResponse
    {
        if ($result instanceof ConnectionException) {
            return RerankResponse::failed(self::isTimeout($result) ? 'timeout' : 'connection');
        }

        if ($result instanceof Throwable) {
            return RerankResponse::failed(self::isTimeout($result) ? 'timeout' : 'connection');
        }

        if (! $result instanceof Response) {
            return RerankResponse::failed('connection');
        }

        if (! $result->successful()) {
            return RerankResponse::failed('http_'.$result->status());
        }

        $rows = $result->json('results');

        if (! is_array($rows)) {
            return RerankResponse::failed('bad_response');
        }

        $scores = [];

        foreach ($rows as $row) {
            $index = is_array($row) ? ($row['index'] ?? null) : null;
            $score = is_array($row) ? ($row['relevance_score'] ?? $row['score'] ?? null) : null;

            if (! is_int($index) || $index < 0 || $index >= $documentCount || ! is_numeric($score)) {
                return RerankResponse::failed('bad_response');
            }

            $scores[] = new RerankScore($index, (float) $score);
        }

        return new RerankResponse(RerankScore::sort($scores));
    }

    private static function isTimeout(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28');
    }
}

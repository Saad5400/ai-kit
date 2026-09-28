<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Prices one OpenRouter generation after the fact:
 * `GET {base}/generation?id={id}` → `data.total_cost` (USD).
 *
 * OpenRouter bills a generation the client aborted or that died mid-stream,
 * but the stream that would have carried its `usage.cost` never reached the
 * final chunk. The generation's stats are written asynchronously — the
 * endpoint answers 404 (or a body without a cost) for the first seconds —
 * so {@see fetch()} is one look that reads "not yet" as null, and
 * {@see resolve()} / {@see resolveMany()} retry inside a short, bounded
 * window for the synchronous fast path. The patient retries belong to
 * {@see ResolveInterruptedSpend}, on the queue.
 *
 * It uses the same credentials the gateway uses: the laravel/ai provider
 * config named by `ai-kit.spend.provider` (key, and `url` when overridden).
 */
class GenerationCostResolver
{
    /**
     * @param  list<int>  $backoffMs  sleeps between the bounded retries
     */
    public function __construct(
        protected HttpFactory $http,
        protected ?string $apiKey,
        protected string $baseUrl = 'https://openrouter.ai/api/v1',
        protected float $windowSeconds = 6.0,
        protected array $backoffMs = [500, 1000, 1500, 3000],
        protected int $requestTimeoutSeconds = 3,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * One look. The generation's total cost in USD (0.0 is a real answer:
     * the generation billed nothing), or null while OpenRouter has no stats
     * for it yet — or on any failure, which callers treat the same way.
     */
    public function fetch(string $generationId): ?float
    {
        if ($this->apiKey === null || $this->apiKey === '' || $generationId === '') {
            return null;
        }

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->requestTimeoutSeconds)
                ->get($this->baseUrl.'/generation', ['id' => $generationId]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $cost = $response->json('data.total_cost');

        return is_numeric($cost) && (float) $cost >= 0 ? (float) $cost : null;
    }

    /**
     * Retry one id inside the bounded window.
     */
    public function resolve(string $generationId): ?float
    {
        return $this->resolveMany([$generationId])[$generationId] ?? null;
    }

    /**
     * Price several ids under ONE shared window — a turn cut off in a
     * failover has more than one, and the fast path must stay bounded no
     * matter how many. Returns only the ids that resolved.
     *
     * @param  list<string>  $generationIds
     * @return array<string, float>
     */
    public function resolveMany(array $generationIds): array
    {
        $remaining = array_values(array_unique(array_filter($generationIds, fn ($id) => is_string($id) && $id !== '')));
        $resolved = [];
        $started = hrtime(true);
        $slept = 0.0;

        foreach ([0, ...$this->backoffMs] as $round => $sleepMs) {
            if ($round > 0) {
                // Wall time the requests took plus the sleeps so far (the
                // sleeps counted by plan, so a faked Sleep still bounds it).
                $elapsed = max((hrtime(true) - $started) / 1e9, $slept);

                if ($elapsed + $sleepMs / 1000 > $this->windowSeconds) {
                    break;
                }

                Sleep::for($sleepMs)->milliseconds();
                $slept += $sleepMs / 1000;
            }

            foreach ($remaining as $index => $id) {
                $cost = $this->fetch($id);

                if ($cost !== null) {
                    $resolved[$id] = $cost;
                    unset($remaining[$index]);
                }
            }

            if ($remaining === []) {
                break;
            }
        }

        return $resolved;
    }
}

<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Sleep;
use Saad\AiKit\Usage\ResolveInterruptedSpend;
use Throwable;

/**
 * Prices one OpenRouter generation after the fact:
 * `GET {base}/generation?id={id}` → `data.total_cost` (USD).
 *
 * OpenRouter bills a generation the client aborted or that died mid-stream,
 * but the stream that would have carried its `usage.cost` never reached the
 * final chunk. The generation's stats are written asynchronously — the
 * endpoint answers 404 (or a zero cost while tokens are already counted)
 * for the first seconds — so {@see fetch()} is one look that reads "not
 * yet" as null, and {@see resolveMany()} retries inside a short window
 * whose every request is capped by the time left. The patient retries
 * belong to {@see ResolveInterruptedSpend}, on the queue.
 *
 * A configuration that can never work — no key, a refused key (401/403),
 * a `spend.provider` whose driver is not OpenRouter (its key must never be
 * sent to openrouter.ai) — throws {@see GenerationCostUnavailable} instead
 * of looking like "not yet".
 */
class GenerationCostResolver
{
    /** The HTTP status of the last look (null: no response), for give-up logs. */
    public ?int $lastStatus = null;

    /**
     * @param  list<int>  $backoffMs  sleeps between the bounded retries
     * @param  string|null  $misconfigured  why this resolver can never price anything
     */
    public function __construct(
        protected HttpFactory $http,
        protected ?string $apiKey,
        protected string $baseUrl = 'https://openrouter.ai/api/v1',
        protected float $windowSeconds = 0.0,
        protected array $backoffMs = [500, 1000, 1500, 3000],
        protected int $requestTimeoutSeconds = 3,
        protected ?string $misconfigured = null,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');

        if ($this->misconfigured === null && ($this->apiKey === null || $this->apiKey === '')) {
            $this->misconfigured = 'no API key is configured for the spend provider';
        }
    }

    /**
     * Why this resolver can never price anything, or null when it can.
     */
    public function misconfiguration(): ?string
    {
        return $this->misconfigured;
    }

    /**
     * One look. The generation's total cost in USD, or null while OpenRouter
     * has no final stats for it yet (or the request failed transiently). A
     * zero cost is only final when no tokens were counted — or when
     * `$acceptZero` says this is the last look.
     *
     * @throws GenerationCostUnavailable
     */
    public function fetch(string $generationId, bool $acceptZero = false, ?float $timeoutSeconds = null): ?float
    {
        if ($this->misconfigured !== null) {
            throw new GenerationCostUnavailable($this->misconfigured);
        }

        if ($generationId === '') {
            return null;
        }

        $this->lastStatus = null;

        try {
            $response = $this->http
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->timeout(max(0.1, min((float) $this->requestTimeoutSeconds, $timeoutSeconds ?? INF)))
                ->get($this->baseUrl.'/generation', ['id' => $generationId]);
        } catch (Throwable) {
            return null;
        }

        $this->lastStatus = $response->status();

        if (in_array($this->lastStatus, [401, 403], true)) {
            throw new GenerationCostUnavailable("OpenRouter refused the spend provider's key (HTTP {$this->lastStatus})");
        }

        if (! $response->successful()) {
            return null;
        }

        $cost = $response->json('data.total_cost');

        if (! is_numeric($cost) || (float) $cost < 0) {
            return null;
        }

        $cost = (float) $cost;

        if ($cost === 0.0 && ! $acceptZero && $this->countedTokens((array) $response->json('data', []))) {
            return null;
        }

        return $cost;
    }

    /**
     * Price several ids under ONE shared window; each request is capped by
     * the time left, so the window bounds the call however many ids there
     * are. Returns only the ids that resolved. A zero window makes no
     * request at all.
     *
     * @param  list<string>  $generationIds
     * @return array<string, float>
     *
     * @throws GenerationCostUnavailable
     */
    public function resolveMany(array $generationIds, ?float $windowSeconds = null): array
    {
        $window = $windowSeconds ?? $this->windowSeconds;
        $remaining = array_values(array_unique(array_filter($generationIds, fn ($id) => is_string($id) && $id !== '')));
        $resolved = [];

        if ($window <= 0 || $remaining === []) {
            return [];
        }

        $started = hrtime(true);
        $slept = 0.0;
        // By reference: the sleeps are counted by plan, so a faked Sleep
        // still bounds the window.
        $elapsed = function () use ($started, &$slept): float {
            return max((hrtime(true) - $started) / 1e9, $slept);
        };

        foreach ([0, ...$this->backoffMs] as $round => $sleepMs) {
            if ($round > 0) {
                if ($elapsed() + $sleepMs / 1000 >= $window) {
                    break;
                }

                Sleep::for($sleepMs)->milliseconds();
                $slept += $sleepMs / 1000;
            }

            foreach ($remaining as $index => $id) {
                $left = $window - $elapsed();

                if ($left <= 0) {
                    break 2;
                }

                $cost = $this->fetch($id, timeoutSeconds: $left);

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

    /**
     * Retry one id inside the window.
     *
     * @throws GenerationCostUnavailable
     */
    public function resolve(string $generationId, ?float $windowSeconds = null): ?float
    {
        return $this->resolveMany([$generationId], $windowSeconds)[$generationId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function countedTokens(array $data): bool
    {
        foreach (['tokens_prompt', 'tokens_completion', 'native_tokens_prompt', 'native_tokens_completion'] as $key) {
            if (is_numeric($data[$key] ?? null) && (int) $data[$key] > 0) {
                return true;
            }
        }

        return false;
    }
}

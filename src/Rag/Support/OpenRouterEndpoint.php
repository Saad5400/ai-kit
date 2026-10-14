<?php

namespace Saad\AiKit\Rag\Support;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Where the retrieval drivers send their requests: the base URL and key of
 * the laravel/ai provider named by `ai-kit.rag.provider` (`openrouter` by
 * default — the same `ai.providers.openrouter.{key,url}` every chat turn
 * already uses, so an app configures nothing new). Read per request, never
 * cached: Octane-safe, and a test or eval variant that changes config
 * mid-process is honoured.
 */
final class OpenRouterEndpoint
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $provider = null,
    ) {}

    public static function make(?string $provider = null): self
    {
        return new self(app(HttpFactory::class), $provider);
    }

    public function provider(): string
    {
        return $this->provider ?? (string) config('ai-kit.rag.provider', 'openrouter');
    }

    public function url(string $path): string
    {
        $base = (string) (config('ai.providers.'.$this->provider().'.url') ?: 'https://openrouter.ai/api/v1');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /** A JSON request carrying the provider's bearer key. */
    public function request(): PendingRequest
    {
        return $this->authorize($this->http->createPendingRequest());
    }

    /**
     * Put the provider's key and the JSON headers on a request — also the
     * way a pooled request (which starts bare) is prepared.
     *
     * @throws RuntimeException when no key is configured
     */
    public function authorize(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->key())->acceptJson()->asJson();
    }

    /** @throws RuntimeException when no key is configured */
    public function key(): string
    {
        $key = (string) config('ai.providers.'.$this->provider().'.key', '');

        if ($key === '') {
            throw new RuntimeException(
                "No API key is configured for the [{$this->provider()}] provider (ai.providers.{$this->provider()}.key) "
                .'— the retrieval drivers cannot call it. Use the `fake` driver in tests.'
            );
        }

        return $key;
    }

    /** The `usage.cost` OpenRouter reported, or null when it reported none. */
    public static function cost(mixed $usage): ?float
    {
        $cost = is_array($usage) ? ($usage['cost'] ?? null) : null;

        return is_numeric($cost) && (float) $cost >= 0 ? (float) $cost : null;
    }
}

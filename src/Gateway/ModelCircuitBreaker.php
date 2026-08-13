<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

/**
 * Per provider+model circuit breaker. Enough step failures inside the
 * rolling window open the circuit for the cooldown; while open, the gateway
 * throws ProviderOverloadedException before any HTTP happens, which is a
 * FailoverableException — so a declared fallback chain skips straight to
 * the next model. After the cooldown, one probe request is let through
 * (half-open): success closes the circuit, failure re-opens it. A quiet
 * half-open window with no traffic closes on its own by expiry.
 */
class ModelCircuitBreaker
{
    public function __construct(
        protected Cache $cache,
        protected int $failureThreshold = 5,
        protected int $windowSeconds = 120,
        protected int $cooldownSeconds = 60,
        protected int $halfOpenSeconds = 30,
    ) {}

    /**
     * Throw when the circuit disallows a request to this model.
     */
    public function guard(string $provider, string $model): void
    {
        if ($this->cache->has($this->key($provider, $model, 'open'))) {
            throw $this->openException($provider, $model);
        }

        if ($this->cache->has($this->key($provider, $model, 'half_open'))
            && ! $this->cache->add($this->key($provider, $model, 'probe'), 1, $this->halfOpenSeconds)) {
            throw $this->openException($provider, $model);
        }
    }

    public function recordFailure(string $provider, string $model): void
    {
        if ($this->cache->has($this->key($provider, $model, 'half_open'))) {
            $this->open($provider, $model);

            return;
        }

        $failures = $this->key($provider, $model, 'failures');

        $this->cache->add($failures, 0, $this->windowSeconds);

        if ($this->cache->increment($failures) >= $this->failureThreshold) {
            $this->open($provider, $model);
        }
    }

    public function recordSuccess(string $provider, string $model): void
    {
        $this->forget($provider, $model);
    }

    public function isOpen(string $provider, string $model): bool
    {
        return $this->cache->has($this->key($provider, $model, 'open'));
    }

    /**
     * Manually close the circuit and clear all state.
     */
    public function reset(string $provider, string $model): void
    {
        $this->forget($provider, $model);
    }

    protected function open(string $provider, string $model): void
    {
        $this->cache->put($this->key($provider, $model, 'open'), 1, $this->cooldownSeconds);
        $this->cache->put($this->key($provider, $model, 'half_open'), 1, $this->cooldownSeconds + $this->halfOpenSeconds);
        $this->cache->forget($this->key($provider, $model, 'probe'));
        $this->cache->forget($this->key($provider, $model, 'failures'));
    }

    protected function forget(string $provider, string $model): void
    {
        foreach (['open', 'half_open', 'probe', 'failures'] as $suffix) {
            $this->cache->forget($this->key($provider, $model, $suffix));
        }
    }

    protected function openException(string $provider, string $model): ProviderOverloadedException
    {
        return new ProviderOverloadedException(
            "Circuit breaker open for [{$model}] on provider [{$provider}]."
        );
    }

    protected function key(string $provider, string $model, string $suffix): string
    {
        return "ai-kit:cb:{$provider}:{$model}:{$suffix}";
    }
}

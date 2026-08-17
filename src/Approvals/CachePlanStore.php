<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Contracts\Cache\Repository as Cache;
use Saad\AiKit\Approvals\Contracts\PlanStore;

/**
 * The default {@see PlanStore}: plans live in a cache store under
 * owner-bound keys with a TTL (`ai-kit.approvals.plan_ttl_seconds`), so an
 * abandoned plan quietly lapses — Discard can stay a client-side no-op.
 * Ownership is verified on fetch: a foreign or unknown id yields null.
 */
class CachePlanStore implements PlanStore
{
    public function __construct(
        protected readonly Cache $cache,
        protected readonly int $ttlSeconds = 3600,
    ) {}

    public function put(Plan $plan, string $owner): void
    {
        $this->cache->put($this->key($plan->id), [
            'owner' => $owner,
            'plan' => $plan->toArray(),
        ], $this->ttlSeconds);
    }

    public function get(string $planId, string $owner): ?Plan
    {
        $data = $this->cache->get($this->key($planId));

        if (! is_array($data) || (string) ($data['owner'] ?? '') !== $owner) {
            return null;
        }

        return Plan::fromArray((array) ($data['plan'] ?? []));
    }

    public function forget(string $planId): void
    {
        $this->cache->forget($this->key($planId));
    }

    protected function key(string $planId): string
    {
        return 'ai-kit:plan:'.$planId;
    }
}

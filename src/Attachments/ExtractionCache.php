<?php

namespace Saad\AiKit\Attachments;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Content-addressed cache for extraction results: keyed on the sha-256 of
 * the FILE BYTES plus a strategy version, so identical uploads share one
 * entry (deliberately not user-scoped — the cached value must therefore be
 * the extraction of the bytes, never anything about who sent them) and a
 * changed extraction strategy invalidates by bumping the version. An
 * unreadable file yields no key and simply bypasses the cache. Cache the
 * fully-assembled context block, not raw text, so a hit skips the whole
 * routing decision.
 */
class ExtractionCache
{
    public function __construct(
        protected Cache $cache,
        protected string $version = 'v1',
        protected int $ttlDays = 14,
    ) {}

    /**
     * @param  Closure(): ?string  $produce
     */
    public function remember(string $absolutePath, Closure $produce): ?string
    {
        $key = $this->key($absolutePath);

        if ($key === null) {
            return $produce();
        }

        $cached = $this->cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $value = $produce();

        if (is_string($value)) {
            $this->cache->put($key, $value, now()->addDays(max(1, $this->ttlDays)));
        }

        return $value;
    }

    public function key(string $absolutePath): ?string
    {
        $hash = @hash_file('sha256', $absolutePath);

        if ($hash === false) {
            return null;
        }

        return 'ai-kit:extract:'.$this->version.':'.$hash;
    }
}

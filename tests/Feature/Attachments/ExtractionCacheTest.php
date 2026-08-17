<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Saad\AiKit\Attachments\ExtractionCache;

function cacheWithFile(string $content, string $version = 'v1'): array
{
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-test-');
    file_put_contents($path, $content);

    return [new ExtractionCache(new Repository(new ArrayStore), $version), $path];
}

it('produces once per byte-identical content and version', function () {
    [$cache, $path] = cacheWithFile('same bytes');
    $calls = 0;
    $produce = function () use (&$calls): string {
        $calls++;

        return 'extracted';
    };

    expect($cache->remember($path, $produce))->toBe('extracted')
        ->and($cache->remember($path, $produce))->toBe('extracted')
        ->and($calls)->toBe(1);

    @unlink($path);
});

it('never caches a null production (a scanned PDF re-decides)', function () {
    [$cache, $path] = cacheWithFile('scanned');
    $calls = 0;
    $produce = function () use (&$calls): ?string {
        $calls++;

        return null;
    };

    expect($cache->remember($path, $produce))->toBeNull()
        ->and($cache->remember($path, $produce))->toBeNull()
        ->and($calls)->toBe(2);

    @unlink($path);
});

it('bypasses the cache for an unreadable file', function () {
    $cache = new ExtractionCache(new Repository(new ArrayStore));

    expect($cache->key('/nonexistent/file.pdf'))->toBeNull()
        ->and($cache->remember('/nonexistent/file.pdf', fn (): string => 'produced'))->toBe('produced');
});

it('separates entries by strategy version', function () {
    [$v1, $path] = cacheWithFile('bytes');
    $v2 = new ExtractionCache(new Repository(new ArrayStore), 'v2');

    expect($v1->key($path))->not->toBe($v2->key($path))
        ->and($v1->key($path))->toStartWith('ai-kit:extract:v1:');

    @unlink($path);
});

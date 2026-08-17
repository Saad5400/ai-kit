<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class StreamingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SseStream::class);

        // The mapper is stateful per turn (text transformers hold text
        // between deltas), so it binds transiently — every resolve is a
        // fresh fold.
        $this->app->bind(StreamEventMapper::class);

        $this->app->singleton(TurnBuffer::class, function (Application $app) {
            $config = $app['config']->get('ai-kit.streaming', []);

            return new TurnBuffer(
                $app['cache']->store($config['cache_store'] ?? null),
                (int) ($config['ttl_seconds'] ?? 7200),
                (int) ($config['max_stream_seconds'] ?? 180),
                (int) ($config['keepalive_seconds'] ?? 15),
                (int) ($config['poll_interval_ms'] ?? 150),
            );
        });
    }
}

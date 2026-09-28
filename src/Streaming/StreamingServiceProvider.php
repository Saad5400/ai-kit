<?php

namespace Saad\AiKit\Streaming;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Saad\AiKit\Safety\KillSwitch;

class StreamingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SseStream::class);

        // The mapper is stateful per turn (text transformers hold text
        // between deltas), so it binds transiently — every resolve is a
        // fresh fold.
        $this->app->bind(StreamEventMapper::class);

        // The kill switch only exists when the safety module is on; the
        // runner degrades to no re-check rather than dragging the module in.
        $this->app->bind(TurnRunner::class, fn (Application $app) => new TurnRunner(
            $app->bound(KillSwitch::class) ? $app->make(KillSwitch::class) : null,
        ));

        // Per request / job: it holds the runs streaming right now.
        $this->app->scoped(InterruptedTurns::class, fn (Application $app) => new InterruptedTurns(
            (bool) $app['config']->get('ai-kit.streaming.remember_interrupted_turns', true),
        ));

        $this->app->singleton(TurnBuffer::class, function (Application $app) {
            $config = $app['config']->get('ai-kit.streaming', []);

            return new TurnBuffer(
                $app['cache']->store($config['cache_store'] ?? null),
                (int) ($config['ttl_seconds'] ?? 7200),
                (int) ($config['max_stream_seconds'] ?? 180),
                (int) ($config['keepalive_seconds'] ?? 15),
                (int) ($config['poll_interval_ms'] ?? 150),
                (int) ($config['page_size'] ?? 64),
                (int) ($config['stale_after_seconds'] ?? 300),
                (bool) ($config['stale_trailing_done'] ?? false),
            );
        });
    }

    public function boot(Dispatcher $events): void
    {
        // Track every run, prompted or streamed (the dispatcher matches
        // interfaces, not parent classes, so both pairs are listed), seal
        // it the moment a step fails — inside the loop, before the throw
        // reaches RememberConversation's catch on either path — with
        // AgentFailed as the fallback for a failure outside a step (it too
        // precedes the catch on the streamed path, under the same retry
        // guard). A seal is idempotent; a run that finishes is forgotten.
        $turns = fn (): InterruptedTurns => $this->app->make(InterruptedTurns::class);

        $events->listen([PromptingAgent::class, StreamingAgent::class], fn (PromptingAgent $event) => $turns()->track($event->invocationId, $event->prompt));
        $events->listen(StepFailed::class, fn (StepFailed $event) => $turns()->sealTracked($event->invocationId));
        $events->listen(AgentFailed::class, fn (AgentFailed $event) => $turns()->seal($event->prompt, $event->invocationId));
        $events->listen([AgentPrompted::class, AgentStreamed::class], fn (AgentPrompted $event) => $turns()->forget($event->invocationId));
    }
}

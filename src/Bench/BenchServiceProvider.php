<?php

namespace Saad\AiKit\Bench;

use Illuminate\Support\ServiceProvider;

class BenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TurnDriver is deliberately NOT defaulted: only the app knows how
        // to run one of its turns. Resolving the runner without binding it
        // is a hard error with a clear message, not a silent no-op.
        $this->app->bind(BenchRunner::class, function ($app): BenchRunner {
            if (! $app->bound(TurnDriver::class)) {
                throw new \RuntimeException(
                    'No '.TurnDriver::class.' is bound. Bind your app\'s driver in a service provider before running the bench.',
                );
            }

            return new BenchRunner($app->make(TurnDriver::class));
        });

        $this->app->singleton(ReportWriter::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\BenchCommand::class]);
        }
    }
}

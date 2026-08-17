<?php

namespace Saad\AiKit\Credits;

use Illuminate\Support\ServiceProvider;

class CreditsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditCalculator::class);

        // CreditDebitor is deliberately NOT defaulted: wallet policy is the
        // app's (which wallets pay, in what order, resets, caps). Resolving
        // the meter without binding it is a hard error, not a silent no-op.
        $this->app->singleton(CreditMeter::class);
    }

    public function boot(): void
    {
        //
    }
}

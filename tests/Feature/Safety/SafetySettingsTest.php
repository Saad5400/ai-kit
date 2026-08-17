<?php

use Illuminate\Support\Facades\Event;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\ConfigSafetySettings;
use Saad\AiKit\Safety\Events\KillSwitchReleased;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;
use Saad\AiKit\Safety\KillSwitch;
use Saad\AiKit\Safety\SafetySettings;

it('binds the config-backed settings by default', function () {
    $settings = app(SafetySettings::class);

    expect($settings)->toBeInstanceOf(ConfigSafetySettings::class)
        ->and($settings->enabled())->toBeTrue()
        ->and($settings->enabled('chat'))->toBeTrue()
        ->and($settings->dailyBudgetUsd())->toBeNull();
});

it('the master toggle disables every feature', function () {
    config()->set('ai-kit.safety.enabled', false);

    $settings = app(SafetySettings::class);

    expect($settings->enabled())->toBeFalse()
        ->and($settings->enabled('chat'))->toBeFalse();
});

it('feature toggles disable only their own surface', function () {
    config()->set('ai-kit.safety.features', ['admin' => false, 'chat' => true]);

    $settings = app(SafetySettings::class);

    expect($settings->enabled())->toBeTrue()
        ->and($settings->enabled('admin'))->toBeFalse()
        ->and($settings->enabled('chat'))->toBeTrue()
        ->and($settings->enabled('never-declared'))->toBeTrue();
});

it('reads the daily budget from config', function () {
    config()->set('ai-kit.safety.daily_usd_limit', 12.5);

    expect(app(SafetySettings::class)->dailyBudgetUsd())->toBe(12.5);
});

it('trips the kill switch for a feature disabled in settings', function () {
    config()->set('ai-kit.safety.features', ['admin' => false]);

    $switch = app(KillSwitch::class);

    expect($switch->engaged('admin'))->toBeTrue()
        ->and($switch->engaged('chat'))->toBeFalse()
        ->and($switch->engaged())->toBeFalse()
        ->and(fn () => $switch->enforce('admin'))->toThrow(AiKilledException::class);

    $switch->enforce('chat');
});

it('trips the kill switch globally when the master toggle is off', function () {
    config()->set('ai-kit.safety.enabled', false);

    $switch = app(KillSwitch::class);

    expect($switch->engaged())->toBeTrue()
        ->and($switch->engaged('chat'))->toBeTrue()
        ->and(fn () => $switch->enforce())->toThrow(AiKilledException::class);
});

it('reads a budget of zero or less as exhausted', function () {
    config()->set('ai-kit.safety.daily_usd_limit', 0.0);

    $guard = app(BudgetGuard::class);

    expect($guard->exceeded())->toBeTrue()
        ->and($guard->remaining())->toEqualWithDelta(0.0, 0.000001)
        ->and(fn () => $guard->enforce())->toThrow(BudgetExceededException::class);

    config()->set('ai-kit.safety.daily_usd_limit', -5.0);

    expect($guard->exceeded())->toBeTrue();
});

it('lets the budget guard follow live settings changes', function () {
    $guard = app(BudgetGuard::class);

    expect($guard->limit())->toBeNull();

    config()->set('ai-kit.safety.daily_usd_limit', 3.0);

    expect($guard->limit())->toBe(3.0)
        ->and($guard->remaining())->toEqualWithDelta(3.0, 0.000001);
});

it('lets apps rebind the contract with their own store', function () {
    $store = new class implements SafetySettings
    {
        public bool $on = true;

        public ?float $budget = 5.0;

        public function enabled(?string $feature = null): bool
        {
            return $this->on;
        }

        public function dailyBudgetUsd(): ?float
        {
            return $this->budget;
        }
    };

    $this->app->instance(SafetySettings::class, $store);
    $this->app->forgetInstance(KillSwitch::class);
    $this->app->forgetInstance(BudgetGuard::class);

    expect(app(BudgetGuard::class)->limit())->toBe(5.0)
        ->and(app(KillSwitch::class)->engaged())->toBeFalse();

    $store->on = false;
    $store->budget = 0.0;

    expect(app(KillSwitch::class)->engaged())->toBeTrue()
        ->and(app(BudgetGuard::class)->exceeded())->toBeTrue();
});

it('release reports false and stays silent while settings still engage the scope', function () {
    Event::fake([KillSwitchReleased::class]);
    config()->set('ai-kit.safety.features', ['chat' => false]);

    $this->app->forgetInstance(KillSwitch::class);
    $switch = $this->app->make(KillSwitch::class);

    $switch->engage('chat', 'incident');

    expect($switch->release('chat'))->toBeFalse()
        ->and($switch->engaged('chat'))->toBeTrue();
    Event::assertNotDispatched(KillSwitchReleased::class);

    config()->set('ai-kit.safety.features', []);

    expect($switch->release('chat'))->toBeTrue();
    Event::assertDispatched(KillSwitchReleased::class, fn ($e) => $e->scope === 'chat');
});

it('reason falls back to a settings-derived explanation', function () {
    config()->set('ai-kit.safety.features', ['chat' => false]);

    $this->app->forgetInstance(KillSwitch::class);
    $switch = $this->app->make(KillSwitch::class);

    expect($switch->reason('chat'))->toBe(__('ai-kit::safety.disabled_by_settings'))
        ->and($switch->reason('other'))->toBeNull();
});

it('falls back to the constructed limit when settings answer null', function () {
    $store = new class implements SafetySettings
    {
        public ?float $budget = null;

        public function enabled(?string $feature = null): bool
        {
            return true;
        }

        public function dailyBudgetUsd(): ?float
        {
            return $this->budget;
        }
    };

    $guard = new BudgetGuard(cache()->store(), app('events'), 2.5, null, $store);

    expect($guard->limit())->toBe(2.5);

    $store->budget = 9.0;

    expect($guard->limit())->toBe(9.0);
});

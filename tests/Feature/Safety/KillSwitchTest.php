<?php

use Illuminate\Support\Facades\Event;
use Saad\AiKit\Safety\Events\KillSwitchEngaged;
use Saad\AiKit\Safety\Events\KillSwitchReleased;
use Saad\AiKit\Safety\Exceptions\AiKilledException;
use Saad\AiKit\Safety\KillSwitch;

beforeEach(function () {
    $this->switch = $this->app->make(KillSwitch::class);
});

it('is disengaged by default', function () {
    expect($this->switch->engaged())->toBeFalse()
        ->and($this->switch->engaged('chat'))->toBeFalse();
});

it('engages globally, covering every scope', function () {
    $this->switch->engage(reason: 'incident');

    expect($this->switch->engaged())->toBeTrue()
        ->and($this->switch->engaged('chat'))->toBeTrue()
        ->and($this->switch->reason('chat'))->toBe('incident');
});

it('engages a single scope without affecting others', function () {
    $this->switch->engage('admin', 'maintenance');

    expect($this->switch->engaged('admin'))->toBeTrue()
        ->and($this->switch->engaged('chat'))->toBeFalse()
        ->and($this->switch->engaged())->toBeFalse()
        ->and($this->switch->reason('admin'))->toBe('maintenance');
});

it('releases', function () {
    $this->switch->engage('chat');
    $this->switch->release('chat');

    expect($this->switch->engaged('chat'))->toBeFalse();
});

it('dispatches engage and release events', function () {
    Event::fake([KillSwitchEngaged::class, KillSwitchReleased::class]);

    // The singleton resolved in beforeEach holds the pre-fake dispatcher.
    $this->app->forgetInstance(KillSwitch::class);
    $switch = $this->app->make(KillSwitch::class);
    $switch->engage('chat', 'why');
    $switch->release('chat');

    Event::assertDispatched(KillSwitchEngaged::class, fn ($e) => $e->scope === 'chat' && $e->reason === 'why');
    Event::assertDispatched(KillSwitchReleased::class, fn ($e) => $e->scope === 'chat');
});

it('enforce throws a user-presentable exception when engaged', function () {
    $this->switch->engage(reason: 'incident');

    try {
        $this->switch->enforce('chat');
        $this->fail('Expected AiKilledException');
    } catch (AiKilledException $e) {
        expect($e->adminReason)->toBe('incident')
            ->and($e->userFacingReason())->toBe(__('ai-kit::safety.killed'));
    }
});

it('enforce passes when disengaged', function () {
    $this->switch->enforce('chat');

    expect(true)->toBeTrue();
});

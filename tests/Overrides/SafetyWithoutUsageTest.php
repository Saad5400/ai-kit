<?php

use Illuminate\Support\Facades\Event;
use Saad\AiKit\Tests\UsageModuleDisabledTestCase;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;

uses(UsageModuleDisabledTestCase::class);

it('does not subscribe to usage events when the usage module is off', function () {
    expect(Event::hasListeners(TurnUsageRecorded::class))->toBeFalse();
});

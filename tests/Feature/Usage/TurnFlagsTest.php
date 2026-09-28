<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\UsageTurns;
use Saad\AiKit\Usage\UsageEvent;

uses(RefreshDatabase::class);

it('keeps the first value set for a flag and reads them all back', function () {
    TurnContext::flag('wrap_up', 'blank_final');
    TurnContext::flag('wrap_up', 'step_exhaustion');
    TurnContext::flag('markup_leak', true);

    expect(TurnContext::flags())->toBe(['wrap_up' => 'blank_final', 'markup_leak' => true])
        ->and(TurnContext::consumeFlags())->toBe(['wrap_up' => 'blank_final', 'markup_leak' => true])
        ->and(TurnContext::flags())->toBe([])
        ->and(Context::has(TurnContext::FLAGS_KEY))->toBeFalse();
});

it('records the step guard flags on the usage row and clears them for the next turn', function () {
    expect(Schema::hasTable('ai_usage_events'))->toBeTrue();

    TurnContext::flag('wrap_up', 'blank_final');
    TurnContext::flag('markup_leak', true);

    [$event] = UsageTurns::prompted(streamed: true);
    event($event);

    $row = UsageEvent::query()->firstOrFail();

    expect($row->context)->toBe(['wrap_up' => 'blank_final', 'markup_leak' => true])
        ->and(TurnContext::flags())->toBe([]);

    // A clean turn writes no context at all.
    [$clean] = UsageTurns::prompted(streamed: true);
    event($clean);

    expect(UsageEvent::query()->latest('id')->firstOrFail()->context)->toBeNull();
});

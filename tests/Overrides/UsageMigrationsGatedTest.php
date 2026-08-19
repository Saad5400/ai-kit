<?php

use Saad\AiKit\Tests\UsageModuleDisabledTestCase;

uses(UsageModuleDisabledTestCase::class);

it('withholds the usage migration when the module is off', function () {
    expect($this->migrationNames())
        ->not->toContain('2026_08_13_000000_create_ai_usage_events_table')
        ->toContain('2026_08_17_000000_create_agent_conversations_tables');
});

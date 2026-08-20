<?php

use Saad\AiKit\Tests\ApprovalsDisabledTestCase;

uses(ApprovalsDisabledTestCase::class);

/**
 * The reason this file exists: catodemy runs `modules.approvals => false` and
 * owns a pre-existing `ai_write_executions` table. Before the migrations were
 * split per module, the kit's approvals migrations loaded anyway and collided
 * with it, and the app's only recourse was to name each file in
 * `Migrator::withoutMigrations()` — a list that goes stale silently.
 */
it('withholds the approvals migrations when the module is off', function () {
    expect($this->migrationNames())
        ->not->toContain('2026_08_17_000001_create_ai_write_executions_table');
});

it('still loads the shared and other enabled modules migrations', function () {
    expect($this->migrationNames())
        ->toContain('2026_08_17_000000_create_agent_conversations_tables')
        ->toContain('2026_08_13_000000_create_ai_usage_events_table');
});

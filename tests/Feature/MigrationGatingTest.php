<?php

use Illuminate\Support\ServiceProvider;

it('loads the shared and default-enabled modules migrations', function () {
    expect($this->migrationNames())
        // Shared: the laravel/ai conversation tables.
        ->toContain('2026_08_17_000000_create_agent_conversations_tables')
        // approvals => true by default.
        ->toContain('2026_08_17_000000_create_ai_proposals_table')
        ->toContain('2026_08_17_000001_create_ai_write_executions_table')
        // usage => true by default.
        ->toContain('2026_08_13_000000_create_ai_usage_events_table');
});

it('withholds migrations behind a modules sub-feature opt-in', function () {
    // Both subdirectories predate this gating and keep their own opt-in:
    // undo needs `approvals.undo`, ai_models needs a database catalog.
    expect($this->migrationNames())
        ->not->toContain('2026_08_17_110000_create_ai_undo_actions_table')
        ->not->toContain('2026_08_17_100000_create_ai_models_table');
});

it('publishes every loaded migration flat under the ai-kit-migrations tag', function () {
    $paths = ServiceProvider::pathsToPublish(null, 'ai-kit-migrations');

    $sources = array_map(fn (string $path) => basename($path), array_keys($paths));

    expect($sources)
        ->toContain('2026_08_17_000000_create_agent_conversations_tables.php')
        ->toContain('2026_08_17_000000_create_ai_proposals_table.php')
        ->toContain('2026_08_13_000000_create_ai_usage_events_table.php');

    // A destination that kept the module subdirectory would land in
    // `database/migrations/approvals/…`, where the app migrator — which globs
    // one level only — would never find it.
    foreach ($paths as $source => $destination) {
        expect(dirname($destination))->toEndWith(DIRECTORY_SEPARATOR.'migrations')
            ->and(basename($destination))->toBe(basename($source));
    }
});

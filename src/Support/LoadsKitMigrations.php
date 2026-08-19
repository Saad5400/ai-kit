<?php

namespace Saad\AiKit\Support;

/**
 * Migration loading shared by the root provider and the module providers that
 * own a `database/migrations/{module}` subdirectory.
 */
trait LoadsKitMigrations
{
    /**
     * Register a migrations directory with the migrator and with the
     * `ai-kit-migrations` publish tag.
     *
     * Publishing is per-file and flattened rather than directory-to-directory:
     * the migrator globs one level only (`{path}/*_*.php`), so a subdirectory
     * copied into an app as `database/migrations/approvals/…` would sit there
     * and never run.
     */
    protected function loadKitMigrations(string $path): void
    {
        $this->loadMigrationsFrom($path);

        $files = glob($path.'/*_*.php') ?: [];

        $this->publishes(
            array_combine(
                $files,
                array_map(fn (string $file) => database_path('migrations/'.basename($file)), $files),
            ),
            'ai-kit-migrations',
        );
    }
}

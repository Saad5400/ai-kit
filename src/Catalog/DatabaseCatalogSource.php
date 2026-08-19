<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The database-backed catalog: enabled rows of the `ai_models` table in
 * sort order, kept fresh by `ai-kit:sync-models`. Disabled rows do not
 * exist as far as this source is concerned — a pruned model must neither
 * route nor appear in pickers, while its row survives for ops history.
 *
 * An absent table reads as an empty catalog rather than a query error:
 * fallback-chain aliases resolve this source during application boot, which
 * on a fresh deploy can precede `php artisan migrate`.
 */
class DatabaseCatalogSource implements CatalogSource
{
    /** Memoized only once true — a boot-time miss must not stick after migrate. */
    protected bool $tableExists = false;

    public function models(): Collection
    {
        if (! $this->tableExists()) {
            return new Collection;
        }

        return AiModel::query()
            ->enabled()
            ->get()
            ->map(fn (AiModel $model): ModelDefinition => $model->toDefinition())
            ->values();
    }

    /**
     * Resolve by routing id, then by canonical slug.
     *
     * The slug leg is for ids that outlived the catalog: a usage row, a
     * stored conversation or an app setting written while the dated pin was
     * the routing id still has to find its model. Never the reverse — what
     * comes back routes on `key`, so resolving through the slug quietly
     * migrates the caller onto the alias.
     */
    public function find(string $modelId): ?ModelDefinition
    {
        if (! $this->tableExists()) {
            return null;
        }

        $row = AiModel::query()->enabled()->where('key', $modelId)->first()
            ?? AiModel::query()->enabled()->where('canonical_slug', $modelId)->first();

        return $row?->toDefinition();
    }

    protected function tableExists(): bool
    {
        return $this->tableExists
            || ($this->tableExists = Schema::hasTable((new AiModel)->getTable()));
    }
}

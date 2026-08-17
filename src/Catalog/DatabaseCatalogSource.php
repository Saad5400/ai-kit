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

    public function find(string $modelId): ?ModelDefinition
    {
        if (! $this->tableExists()) {
            return null;
        }

        return AiModel::query()
            ->enabled()
            ->where('key', $modelId)
            ->first()
            ?->toDefinition();
    }

    protected function tableExists(): bool
    {
        return $this->tableExists
            || ($this->tableExists = Schema::hasTable((new AiModel)->getTable()));
    }
}

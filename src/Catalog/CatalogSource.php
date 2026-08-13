<?php

namespace Saad\AiKit\Catalog;

use Illuminate\Support\Collection;

/**
 * Where the app's model catalog comes from. uqucc uses the config-only
 * source; catodemy and s-grade add a database-backed source kept fresh by
 * `ai:sync-models` (lands with their adoption milestones).
 */
interface CatalogSource
{
    /**
     * @return Collection<int, ModelDefinition>
     */
    public function models(): Collection;

    public function find(string $modelId): ?ModelDefinition;
}

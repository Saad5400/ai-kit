<?php

namespace Saad\AiKit\Catalog\Console;

use Illuminate\Console\Command;
use Saad\AiKit\Catalog\AiModel;
use Saad\AiKit\Catalog\Catalog;

/**
 * Reconcile the config catalog (`ai-kit.catalog.models`) into the
 * `ai_models` table — the hand-curated config stays the reviewed source of
 * truth and the database is its materialization, so there is no second
 * authoring surface to drift. An ops/deploy command, not a scheduled one;
 * app seeders should call it rather than growing a second reconciliation
 * path.
 *
 * Matching is by `key`. `--prune` DISABLES rows whose key left the config —
 * never deletes — so history and any references survive. The
 * recommended-per-task invariant is asserted against the incoming config
 * BEFORE anything is written: a violating catalog changes nothing.
 *
 * Apps needing extra columns (display names, badge figures) extend this
 * command and override {@see attributesFor} — its `meta` bag is the
 * kit-schema landing spot for anything the kit itself never reads.
 */
class SyncModelsCommand extends Command
{
    protected $signature = 'ai-kit:sync-models
        {--prune : Disable enabled rows whose key is no longer in the config catalog}';

    protected $description = 'Reconcile the ai-kit config catalog into the ai_models table';

    public function handle(): int
    {
        $definitions = $this->definitions();

        if (($violation = $this->recommendedInvariantViolation($definitions)) !== null) {
            $this->error($violation);

            return self::FAILURE;
        }

        $inserted = 0;
        $updated = 0;

        foreach ($definitions as $key => $definition) {
            $attributes = $this->attributesFor((string) $key, $definition);

            $existing = AiModel::query()->where('key', $attributes['key'])->first();

            if ($existing === null) {
                AiModel::query()->create($attributes);
                $inserted++;

                continue;
            }

            $existing->fill($this->backfillOnly($attributes, $existing));

            if ($existing->isDirty()) {
                $existing->save();
                $updated++;
            }
        }

        $pruned = $this->option('prune') ? $this->prune(array_map(strval(...), array_keys($definitions))) : 0;

        $this->info("Synced: {$inserted} inserted, {$updated} updated, {$pruned} disabled.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function definitions(): array
    {
        return config('ai-kit.catalog.models', []);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    protected function attributesFor(string $key, array $definition): array
    {
        return [
            'key' => $key,
            'provider' => $definition['provider'] ?? null,
            'provider_model_id' => $definition['provider_model_id'] ?? null,
            // OpenRouter's dated pin for the build `key` resolves to. Stored
            // beside the routing id, never in place of it.
            'canonical_slug' => $definition['canonical_slug'] ?? null,
            'label' => $definition['label'] ?? null,
            'input_usd_per_million' => $definition['input_usd_per_million'] ?? null,
            'output_usd_per_million' => $definition['output_usd_per_million'] ?? null,
            'context_length' => $definition['context_length'] ?? null,
            'capabilities' => array_values($definition['capabilities'] ?? []),
            'tasks' => array_values($definition['tasks'] ?? []),
            'tags' => array_values($definition['tags'] ?? []),
            'fallbacks' => array_values($definition['fallbacks'] ?? []),
            'provider_max_price' => $definition['provider_max_price'] ?? null,
            'enabled' => (bool) ($definition['enabled'] ?? true),
            'sort_order' => (int) ($definition['sort_order'] ?? 0),
            'meta' => $definition['meta'] ?? [],
        ];
    }

    /**
     * `canonical_slug` fills in but never blanks out.
     *
     * A row synced before the column existed carries null, and a config that
     * simply does not bother to declare the dated pin — most of them — must
     * not wipe one an earlier sync recorded. A declared slug still wins, so
     * re-pinning is a config edit like any other.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function backfillOnly(array $attributes, AiModel $existing): array
    {
        if (($attributes['canonical_slug'] ?? null) === null && $existing->canonical_slug !== null) {
            unset($attributes['canonical_slug']);
        }

        return $attributes;
    }

    /**
     * For every task offered by any enabled entry, exactly one enabled entry
     * tagged `recommended` must offer it. A data guard, not a DB constraint —
     * ops can still retag live rows; {@see Catalog}
     * then takes the first in sort order. Vacuously passes for catalogs
     * that declare no tasks.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     */
    protected function recommendedInvariantViolation(array $definitions): ?string
    {
        $enabled = array_filter($definitions, fn (array $definition): bool => (bool) ($definition['enabled'] ?? true));

        $tasks = array_unique(array_merge(...array_values(array_map(
            fn (array $definition): array => array_values($definition['tasks'] ?? []),
            $enabled,
        )) ?: [[]]));

        foreach ($tasks as $task) {
            $recommended = array_keys(array_filter(
                $enabled,
                fn (array $definition): bool => in_array($task, $definition['tasks'] ?? [], true)
                    && in_array('recommended', $definition['tags'] ?? [], true),
            ));

            if (count($recommended) !== 1) {
                return sprintf(
                    "ai-kit.catalog recommended-per-task invariant violated: task '%s' has %d recommended models (expected exactly 1)%s. Nothing was written.",
                    $task,
                    count($recommended),
                    $recommended === [] ? '' : ': '.implode(', ', array_map(strval(...), $recommended)),
                );
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     */
    protected function prune(array $keys): int
    {
        return AiModel::query()
            ->whereNotIn('key', $keys)
            ->where('enabled', true)
            ->update(['enabled' => false]);
    }
}

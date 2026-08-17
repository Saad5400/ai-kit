<?php

namespace Saad\AiKit\Approvals\Undo;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies one self-describing compensation op. The model class travels
 * INSIDE the payload, so there is no per-action dispatch beyond the op
 * name. Two ops are universal and built in:
 *
 *  - `delete_models` {model, ids} — reverses a create; deletes
 *    instance-by-instance so cascade boot hooks fire;
 *  - `restore_attributes` {model, records: [{id, attributes}]} — reverses
 *    an update; forceFill() bypasses $fillable (the snapshot is raw
 *    getAttributes(); families carrying encrypted columns should not use
 *    this op).
 *
 * App-specific ops (detaches, membership removals) register via
 * {@see extend}. An unknown op is a silent no-op — an old ledger row must
 * never crash an undo written by newer code.
 */
class CompensationApplier
{
    /** @var array<string, Closure(array<string, mixed>): void> */
    protected array $handlers = [];

    /**
     * @param  Closure(array<string, mixed>): void  $handler
     */
    public function extend(string $op, Closure $handler): void
    {
        $this->handlers[$op] = $handler;
    }

    /**
     * @param  array<string, mixed>  $compensation
     */
    public function apply(array $compensation): void
    {
        $op = $compensation['op'] ?? null;

        if (is_string($op) && isset($this->handlers[$op])) {
            ($this->handlers[$op])($compensation);

            return;
        }

        match ($op) {
            'delete_models' => $this->deleteModels($compensation),
            'restore_attributes' => $this->restoreAttributes($compensation),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $compensation
     */
    protected function deleteModels(array $compensation): void
    {
        $model = $this->modelClass($compensation);
        $ids = array_values((array) ($compensation['ids'] ?? []));

        if ($model === null || $ids === []) {
            return;
        }

        $model::query()->whereKey($ids)->get()->each->delete();
    }

    /**
     * @param  array<string, mixed>  $compensation
     */
    protected function restoreAttributes(array $compensation): void
    {
        $model = $this->modelClass($compensation);

        if ($model === null) {
            return;
        }

        foreach ((array) ($compensation['records'] ?? []) as $record) {
            if (! is_array($record) || ! isset($record['id']) || ! is_array($record['attributes'] ?? null)) {
                continue;
            }

            $model::query()->whereKey($record['id'])->first()
                ?->forceFill($record['attributes'])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $compensation
     * @return class-string<Model>|null
     */
    protected function modelClass(array $compensation): ?string
    {
        $model = $compensation['model'] ?? null;

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }
}

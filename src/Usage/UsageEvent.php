<?php

namespace Saad\AiKit\Usage;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per completed agent turn (plus one per failover attempt when
 * enabled) — the canonical usage-event record. Append-only: rows are never
 * updated, so only created_at is maintained.
 */
class UsageEvent extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-kit.usage.table', 'ai_usage_events');
    }

    protected function casts(): array
    {
        return [
            'streamed' => 'boolean',
            'cost_usd' => 'float',
            'generation_ids' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForModel(Builder $query, string $model): Builder
    {
        return $query->where('model', $model);
    }

    public function scopeFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }
}

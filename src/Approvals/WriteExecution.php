<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per write an execute turn actually ran, keyed uniquely by
 * (turn_id, sequence) — the idempotency ledger behind exactly-once plan
 * execution. See {@see WriteExecutions} for the claim/replay mechanics.
 *
 * @property int $id
 * @property string $turn_id
 * @property int $sequence
 * @property string $action_type
 * @property string|null $executed_by
 * @property array<string, mixed>|null $result
 * @property bool $undoable
 */
class WriteExecution extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ai-kit.approvals.write_executions_table', 'ai_write_executions');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'result' => 'array',
            'undoable' => 'boolean',
        ];
    }
}

<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Migrations\AiMigration;
use Saad\AiKit\Conversations\StepsBackfill;

/**
 * laravel/ai 1.0 schema, phase A: moves conversation messages onto
 * `steps` / `status` WITHOUT dropping the 0.10 columns.
 *
 * - Adds `steps` (longText, nullable in this phase) and `status`
 *   (string(25), default `completed`) when missing.
 * - Makes the 0.10 `tool_calls` / `tool_results` nullable: the 1.0 store no
 *   longer writes them, and they are NOT NULL in the 0.10 schema.
 * - Rebuilds `participant_index` as (participant_type, participant_id,
 *   agent), which 1.0's agent-scoped latestConversationId() reads.
 * - Backfills every row still missing `steps`, encryption-aware
 *   ({@see StepsBackfill}): pending approvals survive as `paused` rows, and
 *   steps / meta are sealed the way the bound store writes them.
 *
 * The 0.10 columns stay (with their data) so a worker still running the old
 * code mid-deploy neither crashes on insert nor loses its reads; a row such a
 * worker writes after this ran has no `steps` — re-run
 * `php artisan ai-kit:backfill-conversation-steps` once the deploy settles.
 * Phase B (a later release) drops the old columns and makes `steps` NOT NULL.
 *
 * Every step is guarded, so this is a no-op on tables the vendor 1.0
 * migration created, and safe to re-run. `participant_id` stays the kit's
 * string(64). Runs on pgsql and sqlite alike.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasTable($table)) {
            return;
        }

        $legacy = array_values(array_filter(
            ['tool_calls', 'tool_results'],
            fn (string $column): bool => $schema->hasColumn($table, $column),
        ));

        if ($legacy !== []) {
            $schema->table($table, function (Blueprint $blueprint) use ($legacy) {
                foreach ($legacy as $column) {
                    $blueprint->text($column)->nullable()->change();
                }
            });
        }

        $addSteps = ! $schema->hasColumn($table, 'steps');
        $addStatus = ! $schema->hasColumn($table, 'status');

        if ($addSteps || $addStatus) {
            $schema->table($table, function (Blueprint $blueprint) use ($addSteps, $addStatus) {
                if ($addSteps) {
                    $blueprint->longText('steps')->nullable();
                }

                if ($addStatus) {
                    $blueprint->string('status', 25)->default(MessageStatus::Completed->value);
                }
            });
        }

        $index = collect($schema->getIndexes($table))
            ->first(fn (array $index): bool => $index['name'] === 'participant_index');

        if ($index === null || $index['columns'] !== ['participant_type', 'participant_id', 'agent']) {
            $schema->table($table, function (Blueprint $blueprint) use ($index) {
                if ($index !== null) {
                    $blueprint->dropIndex('participant_index');
                }

                $blueprint->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
            });
        }

        StepsBackfill::configured($this->getConnection())->run();
    }

    /**
     * Irreversible by design: rows written since carry their history only in
     * `steps`, so dropping it would lose them. The 0.10 columns were never
     * touched, which is the rollback story for this phase.
     */
    public function down(): void
    {
        //
    }
};

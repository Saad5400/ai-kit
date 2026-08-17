<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Loaded ONLY when ai-kit.approvals.undo is true — apps without turn undo
// never grow an empty table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // The streaming turn's id — not an FK: turns live in the buffer.
            $table->string('turn_id');
            $table->unsignedInteger('sequence')->default(0);
            // Same string owner key as Proposal.proposed_by.
            $table->string('owner')->index();
            $table->string('action_type');
            // Display-only pointer to what was touched.
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            // The self-describing reversal op; the model class travels inside.
            $table->json('compensation')->nullable();
            $table->boolean('undoable')->default(true);
            $table->text('not_undoable_reason')->nullable();
            // Write-once rows: created_at only.
            $table->timestamp('created_at')->nullable();

            $table->index(['turn_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('ai-kit.approvals.undo_table', 'ai_undo_actions');
    }
};

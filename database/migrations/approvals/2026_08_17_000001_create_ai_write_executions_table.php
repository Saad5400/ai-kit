<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('turn_id')->index();
            $table->unsignedInteger('sequence');
            $table->string('action_type');
            // The app's own string owner key — the kit never assumes a
            // users table.
            $table->string('executed_by')->nullable();
            // The executed write's real result (ids + message), for
            // replay/audit.
            $table->json('result')->nullable();
            $table->boolean('undoable')->default(false);
            $table->timestamps();

            // Exactly-once per gated write: the second attempt at the same
            // (turn, sequence) collides here and is swallowed by the helper.
            $table->unique(['turn_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('ai-kit.approvals.write_executions_table', 'ai_write_executions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            // ULID PK: the ids travel through model output and the chat client.
            $table->ulid('id')->primary();
            $table->string('type');
            $table->string('category')->nullable();
            // {action, category, input, preview} — raw input for the confirm
            // turn's re-validation, normalized preview for the card.
            $table->json('payload');
            $table->text('summary');
            $table->string('status')->default('pending')->index();
            // String so it works for user ids AND owner keys ("telegram:{id}").
            $table->string('proposed_by')->index();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('ai-kit.approvals.proposals_table', 'ai_proposals');
    }
};

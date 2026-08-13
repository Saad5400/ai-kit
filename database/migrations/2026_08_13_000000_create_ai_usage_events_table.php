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
            $table->uuid('invocation_id')->index();
            $table->uuid('conversation_id')->nullable()->index();
            $table->string('participant_type')->nullable();
            $table->string('participant_id')->nullable();
            $table->string('agent')->nullable();
            $table->string('feature')->nullable()->index();
            $table->string('provider');
            $table->string('model');
            $table->boolean('streamed')->default(false);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cache_write_input_tokens')->default(0);
            $table->unsignedInteger('cache_read_input_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('cost_usd', 14, 10)->nullable();
            $table->string('cost_source')->nullable();
            $table->json('generation_ids')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('ttft_ms')->nullable();
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['participant_type', 'participant_id']);
            $table->index(['model', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('ai-kit.usage.table', 'ai_usage_events');
    }
};

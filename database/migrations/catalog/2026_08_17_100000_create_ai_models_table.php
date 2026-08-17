<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Loaded ONLY when ai-kit.catalog.source is 'database' — config-catalog apps
// never grow an empty table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // The public identifier apps route by (the ModelDefinition id).
            $table->string('key')->unique();
            // The swap seam, for rows whose provider slug differs from key.
            $table->string('provider')->nullable();
            $table->string('provider_model_id')->nullable();
            $table->string('label')->nullable();
            // USD per one million tokens; null = meter on provider cost only.
            $table->decimal('input_usd_per_million', 12, 6)->nullable();
            $table->decimal('output_usd_per_million', 12, 6)->nullable();
            $table->unsignedInteger('context_length')->nullable();
            // json() not jsonb() so the same migration runs on SQLite tests.
            $table->json('capabilities');
            $table->json('tasks');
            $table->json('tags');
            $table->json('fallbacks');
            $table->json('provider_max_price')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            // The app's bag (display names, tiers, badges) — kit never reads it.
            $table->json('meta');
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return config('ai-kit.catalog.table', 'ai_models');
    }
};

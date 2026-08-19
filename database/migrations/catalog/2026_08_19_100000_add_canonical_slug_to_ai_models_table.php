<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A separate migration rather than a column on the create migration: catalogs
// already materialized in production must gain the column without a rebuild,
// and rows synced before it existed keep a null slug until the next sync.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            // OpenRouter's dated pin for the build `key` resolved to at sync
            // time (`deepseek/deepseek-v4-flash-0731` for the alias
            // `deepseek/deepseek-v4-flash`). Recorded, never routed on.
            $table->string('canonical_slug')->nullable()->after('provider_model_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('canonical_slug');
        });
    }

    protected function table(): string
    {
        return config('ai-kit.catalog.table', 'ai_models');
    }
};

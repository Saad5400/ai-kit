<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * The laravel/ai 0.10 conversation tables (incl. `approval_state`), with one
 * load-bearing deviation from the vendor migration: `participant_id` is a
 * string(64), not a bigint. Account-less apps store anonymous owner keys
 * (a session id, "telegram:{chatId}", "admin:{id}") that a bigint column
 * rejects on Postgres; bigint user ids still fit a string column.
 *
 * Creates are guarded so this coexists with an already-published vendor
 * migration (or pre-existing app tables): whichever runs first wins, the
 * other becomes a no-op. Table names and the connection follow the vendor
 * config keys, so the stock DatabaseConversationStore works unmodified.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (! Schema::hasTable($conversationsTable)) {
            Schema::create($conversationsTable, function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('participant_type')->nullable();
                $table->string('participant_id', 64)->nullable();
                $table->string('title');
                $table->timestamps();

                $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            });
        }

        if (! Schema::hasTable($messagesTable)) {
            Schema::create($messagesTable, function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->string('participant_type')->nullable();
                $table->string('participant_id', 64)->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->text('tool_calls');
                $table->text('tool_results');
                $table->text('usage');
                $table->text('meta');
                $table->text('approval_state')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
                $table->index(['participant_type', 'participant_id'], 'participant_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ai.conversations.tables.messages', 'agent_conversation_messages'));
        Schema::dropIfExists(config('ai.conversations.tables.conversations', 'agent_conversations'));
    }
};

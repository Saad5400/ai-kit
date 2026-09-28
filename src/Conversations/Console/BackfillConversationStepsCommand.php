<?php

namespace Saad\AiKit\Conversations\Console;

use Illuminate\Console\Command;
use Saad\AiKit\Conversations\StepsBackfill;

/**
 * Re-runs the laravel/ai 1.0 steps backfill the kit's
 * `move_agent_conversation_messages_onto_steps` migration performs.
 *
 * Idempotent: only rows whose `steps` is still NULL are written, plus paused
 * rows whose pending call an old worker answered in the legacy columns since.
 * Run it once a deploy has settled, to convert what workers still on the
 * 0.10 code wrote after the migration ran (the encrypted store also heals
 * such a conversation on first read). Exits non-zero, naming the ids, when
 * rows hold ciphertext this app key cannot decrypt — those are left alone.
 */
class BackfillConversationStepsCommand extends Command
{
    protected $signature = 'ai-kit:backfill-conversation-steps
        {--chunk=100 : How many conversations to convert per batch}';

    protected $description = 'Convert laravel/ai 0.10 conversation message rows (tool_calls / tool_results / approval_state) onto 1.0 steps / status';

    public function handle(): int
    {
        $report = StepsBackfill::configured(chunk: max(1, (int) $this->option('chunk')))->run();

        $this->info($report->written === 0
            ? 'Every conversation message already has steps.'
            : sprintf('Converted %d conversation messages onto steps.', $report->written));

        if ($report->reconciled > 0) {
            $this->info(sprintf('Folded late results into %d paused conversation messages.', $report->reconciled));
        }

        if ($report->hasUndecryptable()) {
            $this->warn($report->undecryptableSummary());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

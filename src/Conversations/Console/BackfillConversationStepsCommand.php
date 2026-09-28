<?php

namespace Saad\AiKit\Conversations\Console;

use Illuminate\Console\Command;
use Saad\AiKit\Conversations\StepsBackfill;

/**
 * Re-runs the laravel/ai 1.0 steps backfill the kit's
 * `move_agent_conversation_messages_onto_steps` migration performs.
 *
 * Idempotent: only rows whose `steps` is still NULL are written. Run it once
 * a deploy has settled, to convert the rows a worker still on the 0.10 code
 * wrote after the migration ran (1.0 reads assistant history from `steps`,
 * so such a row would replay as an empty turn until converted).
 */
class BackfillConversationStepsCommand extends Command
{
    protected $signature = 'ai-kit:backfill-conversation-steps
        {--chunk=100 : How many conversations to convert per batch}';

    protected $description = 'Convert laravel/ai 0.10 conversation message rows (tool_calls / tool_results / approval_state) onto 1.0 steps / status';

    public function handle(): int
    {
        $written = StepsBackfill::configured(chunk: max(1, (int) $this->option('chunk')))->run();

        $this->info($written === 0
            ? 'Every conversation message already has steps.'
            : sprintf('Converted %d conversation messages onto steps.', $written));

        return self::SUCCESS;
    }
}

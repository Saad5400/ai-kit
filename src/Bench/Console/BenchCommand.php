<?php

namespace Saad\AiKit\Bench\Console;

use Illuminate\Console\Command;

class BenchCommand extends Command
{
    protected $signature = 'ai-kit:bench
        {--filter= : Substring or regex on the scenario name}
        {--tag=* : Only scenarios carrying every given tag}
        {--attempts= : Attempts per scenario (pass@k)}
        {--model= : Override the assistant model for every turn}
        {--out= : Output directory (default storage/ai-kit/bench/<Ymd-His>)}
        {--max-cost= : Stop once the cumulative provider cost (USD) exceeds this}
        {--list : Print the scenarios without running them}
        {--json : Print the summary as JSON}';

    protected $description = 'Run the AI assistant scenario benchmark and write a JSON + Markdown report.';

    public function handle(): int
    {
        $this->components->warn('ai-kit:bench is not implemented yet.');

        return self::FAILURE;
    }
}

<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\ToolName;
use Saad\AiKit\Bench\Verdict;

/**
 * Warns when the same read tool is called twice with identical arguments
 * inside one turn record — the model re-fetched what it already had.
 * Which tools are reads: an explicit list, else a prefix heuristic.
 */
final class NoRepeatedReads extends AbstractGrader
{
    /** @var list<string> */
    private array $defaultPrefixes = ['list_', 'get_', 'query_', 'search', 'find_', 'read_', 'app_guide', 'lookup_'];

    /**
     * @param  list<string>  $readTools  exact read-tool names (any casing); empty = prefix heuristic
     * @param  list<string>|null  $prefixes  null reads `ai-kit.bench.read_tool_prefixes`
     */
    public function __construct(private array $readTools = [], private ?array $prefixes = null, private bool $fail = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $reads = ToolName::normalizeAll($this->readTools);
        $prefixes = $this->prefixes ?? $this->config('read_tool_prefixes', $this->defaultPrefixes);
        $repeats = [];

        foreach ($run->turns as $index => $record) {
            $seen = [];

            foreach ($record->toolCalls as $call) {
                $name = ToolName::normalize($call['name']);

                if (! $this->isRead($name, $reads, $prefixes)) {
                    continue;
                }

                $key = $name.'|'.json_encode($this->sortKeys($call['arguments'] ?? []), JSON_UNESCAPED_UNICODE);

                if (isset($seen[$key])) {
                    $repeats[] = ['turn' => $index, 'tool' => $name, 'arguments' => $call['arguments']];
                }

                $seen[$key] = true;
            }
        }

        if ($repeats === []) {
            return $this->pass();
        }

        $message = count($repeats).' repeated read(s): '.implode(', ', array_unique(array_column($repeats, 'tool')));

        return $this->fail ? $this->fail($message, ['repeats' => $repeats]) : $this->warn($message, ['repeats' => $repeats]);
    }

    /**
     * @param  list<string>  $reads
     * @param  list<string>  $prefixes
     */
    private function isRead(string $name, array $reads, array $prefixes): bool
    {
        if ($reads !== []) {
            return in_array($name, $reads, true);
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $v): mixed => $this->sortKeys($v), $value);
    }
}

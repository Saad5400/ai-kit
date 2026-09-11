<?php

namespace Saad\AiKit\Bench;

final class BenchReport
{
    /**
     * @param  list<ScenarioRun>  $runs
     * @param  array<string, mixed>  $meta
     */
    public function __construct(public array $runs, public array $meta = []) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return ['runs' => count($this->runs)];
    }

    public function toJson(): string
    {
        return json_encode(['summary' => $this->summary()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function toMarkdown(): string
    {
        return '';
    }
}

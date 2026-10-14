<?php

namespace Saad\AiKit\Tests\Support;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Rag\Search\HitGroup;
use Saad\AiKit\Rag\Tools\BulkSearchTool;

/** A bulk tool whose engine is a closure: (list<string> $queries, Request) => list<HitGroup>. */
final class BulkSearchStubTool extends BulkSearchTool
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly Closure $engine, int $maxQueries = 6, int $maxPassages = 20, int $maxChars = 9000)
    {
        $this->maxQueries = $maxQueries;
        $this->maxPassages = $maxPassages;
        $this->maxChars = $maxChars;
    }

    public function description(): string
    {
        return 'Search the course material.';
    }

    protected function search(array $queries, Request $request): array
    {
        $this->calls[] = $queries;

        return ($this->engine)($queries, $request);
    }

    protected function additionalSchema(JsonSchema $schema): array
    {
        return ['scope' => $schema->string()->enum(['lecture', 'course'])];
    }

    /** Merge hits whose keys share a `video:` prefix into the first. */
    protected function mergeAdjacent(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $prefix = explode('@', $hit->key())[0];
            if (str_starts_with($prefix, 'video:') && isset($out[$prefix])) {
                continue;
            }
            $out[str_starts_with($prefix, 'video:') ? $prefix : $hit->key()] = $hit;
        }

        return array_values($out);
    }

    public static function returning(HitGroup ...$groups): self
    {
        return new self(fn (array $queries) => array_slice($groups, 0, count($queries)));
    }
}

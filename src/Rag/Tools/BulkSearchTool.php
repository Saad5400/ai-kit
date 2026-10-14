<?php

namespace Saad\AiKit\Rag\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Saad\AiKit\Rag\Search\HitGroup;
use Saad\AiKit\Rag\Search\RetrievedHit;
use Saad\AiKit\Rag\Text\ArabicNormalizer;
use Stringable;

/**
 * The base of an app's ONE search tool for its assistant: several phrasings
 * in one call (`queries[1..N]`), answered by one engine round-trip, printed
 * once per result with a shared length budget.
 *
 * Why bulk: a model that may only ask one query at a time spends turns
 * re-asking in another language or wording, and each answer is printed
 * again in full. Here the model sends the student's own words AND an English
 * and an Arabic phrasing together; the tool dedupes across them and says,
 * per query, whether anything confidently matched.
 *
 * An app extends this with:
 *  - {@see search()} — run the queries (one engine call), return one
 *    {@see HitGroup} per query, in order;
 *  - `description()` — its own usage policy (citation tokens, scope rules);
 *  - optionally {@see additionalSchema()} (scope, filters…),
 *    {@see mergeAdjacent()} (e.g. merge neighbouring video moments) and the
 *    budget properties below.
 *
 * Arguments the kit defines: `queries` (required) and `detail`
 * (`brief`|`full`, default brief), passed to {@see RetrievedHit::render()}.
 */
abstract class BulkSearchTool implements Tool
{
    /** At most this many queries are searched; extra ones are ignored, and the output says so. */
    protected int $maxQueries = 6;

    /** Confident hits printed per query. */
    protected int $perQuery = 5;

    /** Passages in the whole output. */
    protected int $maxPassages = 20;

    /** Characters of passage text in the whole output. */
    protected int $maxChars = 9000;

    /** Low-confidence hits shown, labelled weak, for a query with no confident match. */
    protected int $maxWeak = 2;

    /**
     * Run the queries — ideally ONE engine round-trip for all of them.
     *
     * @param  list<string>  $queries  trimmed, de-duplicated, at most {@see $maxQueries}
     * @return list<HitGroup> one per query, in the same order
     */
    abstract protected function search(array $queries, Request $request): array;

    /**
     * App arguments beside `queries` and `detail`.
     *
     * @return array<string, Type>
     */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Collapse hits of ONE query that should print as one (two adjacent
     * moments of the same video). Called per group, before dedupe and
     * budgeting. The default merges nothing.
     *
     * @param  list<RetrievedHit>  $hits
     * @return list<RetrievedHit>
     */
    protected function mergeAdjacent(array $hits): array
    {
        return $hits;
    }

    /** The `queries` argument's description; override to localise or sharpen it. */
    protected function queriesDescription(): string
    {
        return "1–{$this->maxQueries} search queries, searched together in one call. Include the person's own "
            .'words VERBATIM as one query; for a technical term add one English and one Arabic phrasing. '
            .'To find what matches a document, pass its headings as separate queries.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'queries' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->max($this->maxQueries)
                ->description($this->queriesDescription())
                ->required(),
            'detail' => $schema->string()
                ->enum(['brief', 'full'])
                ->description('Passage length: "brief" (default) for finding things, "full" when you need the whole passage to answer.'),
            ...$this->additionalSchema($schema),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        [$queries, $dropped] = $this->queries($request);

        if ($queries === []) {
            return 'No query was provided. Pass at least one search query in `queries`.';
        }

        $groups = array_values($this->search($queries, $request));

        if (count($groups) !== count($queries)) {
            throw new LogicException(static::class.'::search() must return one HitGroup per query, in order.');
        }

        $groups = array_map(
            fn (HitGroup $group): HitGroup => $group->withHits($this->mergeAdjacent(array_values($group->hits))),
            $groups,
        );

        $output = (new SearchResultsRenderer($this->perQuery, $this->maxPassages, $this->maxChars, $this->maxWeak))
            ->render($groups, $request->string('detail')->toString() !== 'full');

        if ($dropped > 0) {
            $output .= "\n\n({$dropped} more ".($dropped === 1 ? 'query was' : 'queries were')
                ." not searched — at most {$this->maxQueries} per call.)";
        }

        return $output;
    }

    /**
     * The usable queries: strings, trimmed, blanks removed, de-duplicated by
     * their folded form (so «محاضرة» and «محاضره» are one query), capped.
     *
     * @return array{0: list<string>, 1: int} the queries, and how many were cut by the cap
     */
    protected function queries(Request $request): array
    {
        $raw = $request['queries'] ?? [];

        if (is_string($raw)) {
            // A model that sends one string instead of a list still gets an answer.
            $raw = [$raw];
        }

        $queries = [];
        $seen = [];

        foreach (is_array($raw) ? $raw : [] as $query) {
            if (! is_string($query) || ($query = trim($query)) === '') {
                continue;
            }

            $key = ArabicNormalizer::normalizeLower($query);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $queries[] = $query;
            }
        }

        return [array_slice($queries, 0, $this->maxQueries), max(0, count($queries) - $this->maxQueries)];
    }
}

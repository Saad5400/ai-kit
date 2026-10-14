<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Rag\Search\HitGroup;
use Saad\AiKit\Rag\Tools\SearchResultsRenderer;
use Saad\AiKit\Tests\Support\BulkSearchStubTool;
use Saad\AiKit\Tests\Support\RetrievedHitStub as Hit;

it('declares queries[1..N] and detail, plus the app\'s own arguments', function () {
    $schema = (new BulkSearchStubTool(fn () => [], maxQueries: 4))->schema(new JsonSchemaTypeFactory);
    $queries = $schema['queries']->toArray();

    expect(array_keys($schema))->toBe(['queries', 'detail', 'scope'])
        ->and($queries['type'])->toBe('array')
        ->and($queries['items']['type'])->toBe('string')
        ->and($queries['minItems'])->toBe(1)
        ->and($queries['maxItems'])->toBe(4)
        ->and($queries['description'])->toContain('VERBATIM')
        ->and($schema['detail']->toArray()['enum'])->toBe(['brief', 'full']);
});

it('searches all queries in one call, deduping folded repeats and blanks', function () {
    $tool = new BulkSearchStubTool(fn (array $queries) => array_map(fn ($q) => new HitGroup($q), $queries));

    $tool->handle(new Request(['queries' => ['محاضرة ٥', '  ', 'محاضره 5', 'lecture 5']]));

    expect($tool->calls)->toBe([['محاضرة ٥', 'lecture 5']]);
});

it('prints a hit once, under the query that ranked it best, and cross-references it', function () {
    $tool = BulkSearchStubTool::returning(
        new HitGroup('Java features', [Hit::make('moment:a'), Hit::make('lesson:b', 'medium')], ['java']),
        new HitGroup('multithreaded', [Hit::make('lesson:c'), Hit::make('moment:a')]),
    );

    $output = (string) $tool->handle(new Request(['queries' => ['Java features', 'multithreaded']]));

    expect($output)->toBe(<<<'TXT'
Query 1 «Java features» (searched also: java) — 2 results
Passage 1 · ref moment:a · confidence high · also matches query 2
text of moment:a

Passage 2 · ref lesson:b · confidence medium
text of lesson:b

Query 2 «multithreaded» — 2 results
Also: Passage 1 (under query 1).
Passage 3 · ref lesson:c · confidence high
text of lesson:c
TXT);

    expect(preg_match_all('/^Passage \d+/m', $output))->toBe(3);
});

it('says "no confident match" and shows at most two weak hits, labelled', function () {
    $tool = BulkSearchStubTool::returning(
        new HitGroup('asdf', [Hit::make('x', 'low'), Hit::make('y', 'low'), Hit::make('z', 'low')]),
        new HitGroup('nothing'),
    );

    $output = (string) $tool->handle(new Request(['queries' => ['asdf', 'nothing']]));

    expect($output)->toStartWith("Query 1 «asdf» — no confident match. Weak: Passage 1 (low), Passage 2 (low).\nPassage 1 · ref x · confidence low (weak)")
        ->and($output)->not->toContain('ref z')
        ->and($output)->toEndWith('Query 2 «nothing» — no match.');
});

it('drops low hits from a confident query', function () {
    $output = (string) BulkSearchStubTool::returning(new HitGroup('q', [Hit::make('a'), Hit::make('b', 'low')]))
        ->handle(new Request(['queries' => ['q']]));

    expect($output)->toContain('— 1 result')->not->toContain('ref b');
});

it('honours an engine-decided confidence and prints its note', function () {
    $output = (string) BulkSearchStubTool::returning(new HitGroup('q', [Hit::make('a', 'medium')], note: 'Widened from the lecture to the whole course.', confident: false))
        ->handle(new Request(['queries' => ['q']]));

    expect($output)->toContain("no confident match. Weak: Passage 1 (medium).\nNote: Widened from the lecture to the whole course.");
});

it('budgets passages round-robin across queries and says what was left out', function () {
    $hits = fn (string $p) => array_map(fn ($i) => Hit::make("{$p}{$i}"), range(1, 5));
    $tool = new BulkSearchStubTool(fn () => [new HitGroup('one', $hits('a')), new HitGroup('two', $hits('b'))], maxPassages: 3);

    $output = (string) $tool->handle(new Request(['queries' => ['one', 'two']]));

    // a1, b1, a2 — query two is not starved by query one.
    expect(preg_match_all('/^Passage \d+/m', $output))->toBe(3)
        ->and($output)->toContain('ref a1')->toContain('ref b1')->toContain('ref a2')->not->toContain('ref b2')
        ->and($output)->toContain('(3 more results left out to stay within the length budget.)')
        ->and($output)->toContain('(4 more results left out');
});

it('budgets by characters but always prints the first passage', function () {
    $long = str_repeat('x', 50);
    $tool = new BulkSearchStubTool(fn () => [new HitGroup('q', [Hit::make('a', body: $long), Hit::make('b', body: $long)])], maxChars: 40);

    $output = (string) $tool->handle(new Request(['queries' => ['q']]));

    expect($output)->toContain('ref a')->not->toContain('ref b');
});

it('applies the adjacency hook per query before budgeting', function () {
    $output = (string) BulkSearchStubTool::returning(new HitGroup('q', [Hit::make('video:v@1000'), Hit::make('video:v@9000'), Hit::make('file:f')]))
        ->handle(new Request(['queries' => ['q']]));

    expect($output)->toContain('— 2 results')->not->toContain('@9000');
});

it('renders full passages on request', function () {
    $output = (string) BulkSearchStubTool::returning(new HitGroup('q', [Hit::make('a')]))
        ->handle(new Request(['queries' => ['q'], 'detail' => 'full']));

    expect($output)->toContain('text of a (full)');
});

it('caps the number of queries and says so', function () {
    $tool = new BulkSearchStubTool(fn (array $queries) => array_map(fn ($q) => new HitGroup($q), $queries), maxQueries: 2);

    $output = (string) $tool->handle(new Request(['queries' => ['a', 'b', 'c', 'd']]));

    expect($tool->calls)->toBe([['a', 'b']])
        ->and($output)->toEndWith('(2 more queries were not searched — at most 2 per call.)');
});

it('accepts a lone string and answers an empty call without searching', function () {
    $tool = new BulkSearchStubTool(fn (array $queries) => array_map(fn ($q) => new HitGroup($q), $queries));

    $tool->handle(new Request(['queries' => 'one query']));
    $empty = (string) $tool->handle(new Request([]));

    expect($tool->calls)->toBe([['one query']])
        ->and($empty)->toStartWith('No query was provided.');
});

it('refuses an engine that returns the wrong number of groups', function () {
    (new BulkSearchStubTool(fn () => []))->handle(new Request(['queries' => ['a']]));
})->throws(LogicException::class, 'one HitGroup per query');

it('keeps header lines single-line even if a headline is not', function () {
    $output = (new SearchResultsRenderer)->render([new HitGroup('q', [new Hit('k', headline: "two\nlines")])]);

    expect(explode("\n", $output)[1])->toBe('Passage 1 two lines · confidence high');
});

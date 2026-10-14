<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * One labelled query of a golden set (one JSON object per line):
 *
 *     {"gid":"g037","queries":["Java features","multithreaded"],"course":"java","scope":"course",
 *      "anchor":{"step":"…"},"asker":{"track":"…"},"layer":"engine","intent":"bulk","caller":"assistant",
 *      "relevant":[{"kind":"moment","id":"qLbXNaxR","grade":2,"t_ms":[68022,230000]}],
 *      "expect_empty":false,"track_dependent":false,"phenomena":["mixed_script"],
 *      "label_source":"…","label_status":"silver|gold|needs_label", …}
 *
 * `relevant[].kind` is the app's vocabulary; {@see Metrics} only needs to
 * know which kinds are a LESSON (a moment inside it counts for it) and that
 * `moment` targets carry a `t_ms` window. Unknown fields are kept in `raw`.
 */
final class GoldenItem
{
    /**
     * @param  list<string>  $queries
     * @param  list<array{kind: string, id: string, grade: int, t_ms?: array{0: int, 1: int}}>  $relevant
     * @param  list<string>  $phenomena
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $gid,
        public readonly array $queries,
        public readonly array $relevant,
        public readonly string $intent = '',
        public readonly string $caller = '',
        public readonly ?string $course = null,
        public readonly ?string $scope = null,
        public readonly string $labelStatus = 'silver',
        public readonly string $layer = 'engine',
        public readonly bool $expectEmpty = false,
        public readonly bool $trackDependent = false,
        public readonly array $phenomena = [],
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            gid: (string) $data['gid'],
            queries: self::queriesFrom($data),
            relevant: array_values(array_map(static fn (array $target): array => [
                ...$target,
                'kind' => (string) ($target['kind'] ?? ''),
                'id' => (string) ($target['id'] ?? ''),
                'grade' => (int) ($target['grade'] ?? 1),
            ], $data['relevant'] ?? [])),
            intent: (string) ($data['intent'] ?? ''),
            caller: (string) ($data['caller'] ?? ''),
            course: isset($data['course']) ? (string) $data['course'] : null,
            scope: isset($data['scope']) ? (string) $data['scope'] : null,
            labelStatus: (string) ($data['label_status'] ?? 'silver'),
            layer: (string) ($data['layer'] ?? 'engine'),
            expectEmpty: (bool) ($data['expect_empty'] ?? false),
            trackDependent: (bool) ($data['track_dependent'] ?? false),
            phenomena: array_values(array_map('strval', $data['phenomena'] ?? [])),
            raw: $data,
        );
    }

    /** The single query (the first, for a bulk item). */
    public function query(): string
    {
        return $this->queries[0] ?? '';
    }

    public function isBulk(): bool
    {
        return count($this->queries) > 1;
    }

    /**
     * `queries` when present; else `query`, which older sets used to carry a
     * bulk item as a JSON-encoded list of strings.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function queriesFrom(array $data): array
    {
        if (isset($data['queries']) && is_array($data['queries'])) {
            return array_values(array_map('strval', $data['queries']));
        }

        $query = (string) ($data['query'] ?? '');

        if (str_starts_with(ltrim($query), '[')) {
            $decoded = json_decode($query, true);

            if (is_array($decoded) && array_is_list($decoded) && $decoded !== []) {
                return array_values(array_map('strval', $decoded));
            }
        }

        return $query === '' ? [] : [$query];
    }
}

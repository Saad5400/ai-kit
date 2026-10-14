<?php

namespace Saad\AiKit\Rag\Glossary;

/**
 * What {@see GlossaryExpander} made of one query.
 *
 *  - `groups` — one OR-group per query position, each a list of FOLDED forms:
 *    the query's own token(s) first, then the glossary alternatives. A lexical
 *    leg ORs within a group and measures coverage across groups.
 *  - `expansions` — what to tell the user ("also searched: …"): each matched
 *    phrase as typed, with its alternatives as authored.
 *  - `rewrites` — whole-query rewrites with the matched phrases substituted
 *    (preferred spellings first), never including the original. They are
 *    ADDED to the original query as extra leg inputs, never substituted for it.
 */
final class QueryExpansion
{
    /**
     * @param  list<list<string>>  $groups
     * @param  list<array{term: string, alternatives: list<string>}>  $expansions
     * @param  list<string>  $rewrites
     */
    public function __construct(
        public readonly string $original,
        public readonly string $normalized,
        public readonly array $groups,
        public readonly array $expansions,
        public readonly array $rewrites,
    ) {}

    public function expanded(): bool
    {
        return $this->expansions !== [];
    }

    /**
     * Every folded form across all groups — what a client highlighter marks.
     *
     * @return list<string>
     */
    public function matchTerms(): array
    {
        return array_values(array_unique(array_merge(...($this->groups ?: [[]]))));
    }

    /**
     * @return array{original: string, normalized: string, groups: list<list<string>>, expansions: list<array{term: string, alternatives: list<string>}>, rewrites: list<string>}
     */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'groups' => $this->groups,
            'expansions' => $this->expansions,
            'rewrites' => $this->rewrites,
        ];
    }
}

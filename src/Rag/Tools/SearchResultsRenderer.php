<?php

namespace Saad\AiKit\Rag\Tools;

use Saad\AiKit\Rag\Search\Confidence;
use Saad\AiKit\Rag\Search\HitGroup;
use Saad\AiKit\Rag\Search\RetrievedHit;

/**
 * Turns the hit groups of a bulk search into the text a model reads:
 *
 *     Query 1 «قصدي حق ال MULTITHREADED» (searched also: multithreading) — 2 results
 *     Passage 1 [start_ms=154622] — lesson "…" · ref moment:… · confidence high · also matches query 3
 *     <passage>
 *
 *     Query 2 «…» — no confident match. Weak: Passage 4 (low).
 *     Passage 4 … · confidence low (weak)
 *     <passage>
 *
 * Rules:
 *  - A hit (by {@see RetrievedHit::key()}) is printed ONCE, under the query
 *    that ranked it best (ties: the earlier query), annotated with every
 *    other query it matched; those queries point at it ("Also: Passage 1").
 *  - A confident query prints its hits of at least Medium confidence, up to
 *    `perQuery`; a query with no confident hit prints "no confident match"
 *    and at most `maxWeak` Low hits, each labelled weak.
 *  - The whole output is budgeted (`maxPassages`, `maxChars` of passage
 *    text), filled round-robin across queries so one query cannot starve the
 *    rest; what did not fit is counted, never silently dropped. The first
 *    passage always fits.
 *  - Every passage line starts `Passage N`, numbered in print order — the
 *    grounding contract an app can match with `/^Passage \d+/m`.
 */
final class SearchResultsRenderer
{
    public function __construct(
        public readonly int $perQuery = 5,
        public readonly int $maxPassages = 20,
        public readonly int $maxChars = 9000,
        public readonly int $maxWeak = 2,
    ) {}

    /**
     * @param  list<HitGroup>  $groups
     */
    public function render(array $groups, bool $brief = true): string
    {
        $groups = array_values($groups);

        // 1. Candidates per query, and each candidate's owner.
        $candidates = [];
        $weak = [];
        $owner = [];      // key => [position, query index]
        $matchedBy = [];  // key => list<query index>

        foreach ($groups as $q => $group) {
            $weak[$q] = ! $group->isConfident();
            $list = $weak[$q]
                ? array_slice($group->hits, 0, max(0, $this->maxWeak))
                : array_slice(array_values(array_filter(
                    $group->hits,
                    static fn (RetrievedHit $hit): bool => $hit->confidence()->atLeast(Confidence::Medium),
                )), 0, max(0, $this->perQuery));

            $candidates[$q] = [];

            foreach ($list as $position => $hit) {
                $key = $hit->key();

                if (isset($candidates[$q][$key])) {
                    continue;
                }

                $candidates[$q][$key] = $hit;
                $matchedBy[$key][] = $q;

                if (! isset($owner[$key]) || $position < $owner[$key][0]) {
                    $owner[$key] = [$position, $q];
                }
            }
        }

        // 2. Round-robin selection under the budget.
        $owned = [];
        foreach ($candidates as $q => $list) {
            $owned[$q] = array_values(array_filter(
                $list,
                static fn (RetrievedHit $hit): bool => $owner[$hit->key()][1] === $q,
            ));
        }

        $selected = [];   // key => true
        $selectedCount = 0;
        $chars = 0;
        $depth = $owned === [] ? 0 : max(array_map('count', $owned));

        for ($r = 0; $r < $depth; $r++) {
            foreach ($owned as $list) {
                if (! isset($list[$r])) {
                    continue;
                }

                $length = mb_strlen($list[$r]->render($brief));

                if ($selectedCount > 0 && ($selectedCount >= $this->maxPassages || $chars + $length > $this->maxChars)) {
                    continue;
                }

                $selected[$list[$r]->key()] = true;
                $selectedCount++;
                $chars += $length;
            }
        }

        // 3. Number in print order.
        $numbers = [];
        $n = 0;
        foreach ($owned as $list) {
            foreach ($list as $hit) {
                if (isset($selected[$hit->key()])) {
                    $numbers[$hit->key()] = ++$n;
                }
            }
        }

        // 4. Print.
        $blocks = [];

        foreach ($groups as $q => $group) {
            $printed = [];
            $shared = [];
            $omitted = 0;

            foreach ($candidates[$q] as $key => $hit) {
                if (! isset($numbers[$key])) {
                    $omitted += $owner[$key][1] === $q ? 1 : 0;

                    continue;
                }

                if ($owner[$key][1] === $q) {
                    $printed[] = $this->passage($hit, $numbers[$key], $brief, $weak[$q], $q, $matchedBy[$key]);
                } else {
                    $shared[] = 'Passage '.$numbers[$key].' (under query '.($owner[$key][1] + 1).')';
                }
            }

            $lines = [$this->header($group, $q, $weak[$q], count($printed) + count($shared), $candidates[$q], $numbers)];

            if ($group->note !== null && $group->note !== '') {
                $lines[] = 'Note: '.$group->note;
            }

            if ($shared !== []) {
                $lines[] = 'Also: '.implode(', ', $shared).'.';
            }

            if ($omitted > 0) {
                $lines[] = "({$omitted} more ".($omitted === 1 ? 'result' : 'results').' left out to stay within the length budget.)';
            }

            $blocks[] = implode("\n", $lines).($printed === [] ? '' : "\n".implode("\n\n", $printed));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, RetrievedHit>  $candidates
     * @param  array<string, int>  $numbers
     */
    private function header(HitGroup $group, int $q, bool $weak, int $count, array $candidates, array $numbers): string
    {
        $header = 'Query '.($q + 1).' «'.$group->query.'»';

        if ($group->searchedAlso !== []) {
            $header .= ' (searched also: '.implode(', ', $group->searchedAlso).')';
        }

        if ($group->hits === []) {
            return $header.' — no match.';
        }

        if ($weak) {
            $refs = [];
            foreach ($candidates as $key => $hit) {
                if (isset($numbers[$key])) {
                    $refs[] = 'Passage '.$numbers[$key].' ('.$hit->confidence()->value.')';
                }
            }

            return $header.' — no confident match.'.($refs === [] ? '' : ' Weak: '.implode(', ', $refs).'.');
        }

        return $header.' — '.$count.' '.($count === 1 ? 'result' : 'results');
    }

    /**
     * @param  list<int>  $matchedBy  query indexes (0-based) that returned this hit
     */
    private function passage(RetrievedHit $hit, int $number, bool $brief, bool $weak, int $q, array $matchedBy): string
    {
        $headline = trim(str_replace(["\r", "\n"], ' ', $hit->headline()));
        $line = 'Passage '.$number.($headline === '' ? '' : ' '.$headline)
            .' · confidence '.$hit->confidence()->value.($weak ? ' (weak)' : '');

        $others = array_values(array_filter($matchedBy, static fn (int $other): bool => $other !== $q));

        if ($others !== []) {
            $line .= ' · also matches query '.implode(', ', array_map(static fn (int $i): int => $i + 1, $others));
        }

        return $line."\n".$hit->render($brief);
    }
}

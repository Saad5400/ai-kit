<?php

namespace Saad\AiKit\Rag\Glossary;

use Saad\AiKit\Rag\Text\ArabicNormalizer;

/**
 * Query expansion over app-supplied glossary data: «لكتشر ٥» becomes the
 * groups [[لكتشر, محاضره, lecture], [5, الخامس, …]] plus the rewrite
 * «محاضرة 5», and the user can be told "also searched: محاضرة, lecture".
 *
 * Matching is on FOLDED tokens ({@see ArabicNormalizer::normalizeLower()}),
 * longest phrase first, left to right, so `past exams` wins over `exams`. A
 * token that matches nothing as written is retried with one Arabic proclitic
 * removed («اللكتشر» → «لكتشر», «بالسترنق» → «سترنق»), single-word forms
 * only. Nothing is ever dropped: an unmatched token is its own one-form group.
 *
 * Pure and deterministic: the same sources and query always give the same
 * expansion. Fuzzy matching (pg_trgm against the variants) is the app's —
 * it hands the matched terms in as one more source.
 */
final class GlossaryExpander
{
    /** Proclitics tried, longest first, when a token matches nothing as written. */
    public const PROCLITICS = ['وال', 'بال', 'فال', 'كال', 'لل', 'ال'];

    /** @var list<GlossarySource> */
    private readonly array $sources;

    /** @var array<string, list<array{term: GlossaryTerm, form: string}>>|null folded phrase => entries */
    private ?array $index = null;

    private int $longest = 1;

    /**
     * @param  GlossarySource|list<GlossarySource>  $sources
     * @param  list<string>  $proclitics  pass [] to match tokens only as written
     */
    public function __construct(
        GlossarySource|array $sources,
        private readonly int $maxRewrites = 3,
        private readonly int $maxAlternatives = 8,
        private readonly array $proclitics = self::PROCLITICS,
    ) {
        $this->sources = is_array($sources) ? array_values($sources) : [$sources];
    }

    public function expand(string $query): QueryExpansion
    {
        $folded = array_map('mb_strtolower', ArabicNormalizer::tokens($query));
        $surface = self::surfaceTokens($query);

        // The two tokenisations agree by construction (folding never adds or
        // removes whitespace); if they ever did not, fall back to the folded
        // tokens rather than misalign a rewrite.
        if (count($surface) !== count($folded)) {
            $surface = $folded;
        }

        $groups = [];
        $expansions = [];
        $parts = []; // per position: ['text' => surface] or ['span' => [...]]
        $count = count($folded);

        for ($i = 0; $i < $count;) {
            $match = $this->matchAt($folded, $i);

            if ($match === null) {
                $groups[] = [$folded[$i]];
                $parts[] = ['text' => $surface[$i]];
                $i++;

                continue;
            }

            [$length, $entries, $matchedFold] = $match;
            $typed = implode(' ', array_slice($surface, $i, $length));
            $phraseFold = implode(' ', array_slice($folded, $i, $length));

            // The bare form a proclitic hid («اللكتشر» → «لكتشر») joins the group too.
            $group = array_values(array_unique([$phraseFold, $matchedFold]));
            $display = [];
            $substitutes = [];

            foreach ($entries as $entry) {
                $term = $entry['term'];
                $preferredFold = self::fold($term->term);
                // Typed in the preferred spelling already: expanded for
                // matching, but a rewrite keeps it as typed.
                $typedPreferred = $preferredFold === $matchedFold;

                foreach ($term->forms() as $form) {
                    $formFold = self::fold($form);

                    if ($formFold === $matchedFold) {
                        continue;
                    }

                    if (! in_array($formFold, $group, true) && count($group) <= $this->maxAlternatives) {
                        $group[] = $formFold;
                    }

                    if (in_array($form, $display, true) || $formFold === $phraseFold) {
                        continue;
                    }

                    $display[] = $form;

                    // Rewrites substitute the preferred spelling, then only
                    // forms in ANOTHER script than the typed one («لكتشر» →
                    // `lecture`): a same-script variant adds nothing a lexical
                    // OR-group does not already cover.
                    if (! $typedPreferred && ($formFold === $preferredFold || self::script($form) !== self::script($typed))) {
                        $substitutes[] = ArabicNormalizer::normalizeDigits(ArabicNormalizer::stripMarks($form));
                    }
                }
            }

            $groups[] = $group;
            $parts[] = ['span' => $substitutes, 'text' => $typed];

            if ($display !== []) {
                $expansions[] = ['term' => $typed, 'alternatives' => array_slice($display, 0, $this->maxAlternatives)];
            }

            $i += $length;
        }

        return new QueryExpansion(
            original: $query,
            normalized: ArabicNormalizer::normalizeLower($query),
            groups: $groups,
            expansions: $expansions,
            rewrites: $this->rewrites($parts, implode(' ', $folded)),
        );
    }

    /**
     * Whole-query rewrites: rewrite r puts each matched span's r-th
     * substitute in place (its last one once it runs out) — the preferred
     * spelling first, then forms in another script. A span with no
     * substitute (typed in its preferred spelling, or with only same-script
     * variants) stays as typed. Duplicates and the original are skipped.
     *
     * @param  list<array{text: string, span?: list<string>}>  $parts
     * @return list<string>
     */
    private function rewrites(array $parts, string $originalFold): array
    {
        $depth = 0;

        foreach ($parts as $part) {
            $depth = max($depth, count($part['span'] ?? []));
        }

        $rewrites = [];
        $seen = [self::fold($originalFold) => true];

        for ($r = 0; $r < $depth && count($rewrites) < $this->maxRewrites; $r++) {
            $words = [];

            foreach ($parts as $part) {
                $substitutes = $part['span'] ?? [];
                $words[] = $substitutes === []
                    ? ArabicNormalizer::normalizeDigits(ArabicNormalizer::stripMarks($part['text']))
                    : $substitutes[min($r, count($substitutes) - 1)];
            }

            $rewrite = implode(' ', $words);
            $key = self::fold($rewrite);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $rewrites[] = $rewrite;
            }
        }

        return $rewrites;
    }

    /**
     * The longest glossary phrase starting at token `$i`, or null.
     *
     * @param  list<string>  $tokens
     * @return array{0: int, 1: list<array{term: GlossaryTerm, form: string}>, 2: string}|null
     */
    private function matchAt(array $tokens, int $i): ?array
    {
        $index = $this->index();

        for ($length = min($this->longest, count($tokens) - $i); $length >= 1; $length--) {
            $phrase = implode(' ', array_slice($tokens, $i, $length));

            if (isset($index[$phrase])) {
                return [$length, $index[$phrase], $phrase];
            }
        }

        foreach ($this->proclitics as $proclitic) {
            $token = $tokens[$i];

            if (str_starts_with($token, $proclitic) && mb_strlen($token) - mb_strlen($proclitic) >= 2) {
                $bare = substr($token, strlen($proclitic));

                if (isset($index[$bare])) {
                    return [1, $index[$bare], $bare];
                }
            }
        }

        return null;
    }

    /** @return array<string, list<array{term: GlossaryTerm, form: string}>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->sources as $source) {
            foreach ($source->terms() as $term) {
                foreach ($term->forms() as $form) {
                    $key = self::fold($form);

                    if ($key === '') {
                        continue;
                    }

                    $index[$key][] = ['term' => $term, 'form' => $form];
                    $this->longest = max($this->longest, substr_count($key, ' ') + 1);
                }
            }
        }

        return $this->index = $index;
    }

    /** `arabic`, `latin` or `other` (digits, symbols) — by the first letter found. */
    private static function script(string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'arabic';
        }

        return preg_match('/\p{L}/u', $text) === 1 ? 'latin' : 'other';
    }

    /** A form folded the way query tokens are: normalised, lowercased, re-joined by single spaces. */
    private static function fold(string $text): string
    {
        return implode(' ', array_map('mb_strtolower', ArabicNormalizer::tokens($text)));
    }

    /**
     * The query's tokens in the author's orthography (canonicalised, tsquery
     * metacharacters removed) — aligned 1:1 with {@see ArabicNormalizer::tokens()}.
     *
     * @return list<string>
     */
    private static function surfaceTokens(string $query): array
    {
        $cleaned = (string) preg_replace(ArabicNormalizer::TOKEN_STRIP_PATTERN, ' ', ArabicNormalizer::canonicalize($query));

        return preg_split('/\s+/u', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

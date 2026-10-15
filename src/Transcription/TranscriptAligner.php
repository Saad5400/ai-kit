<?php

namespace Saad\AiKit\Transcription;

/**
 * Lays a free-text transcript onto a speech-to-text model's word timestamps and
 * cuts it into sentence segments (DECISIONS.md #30).
 *
 * WHY: an audio LLM writes the better WORDS (dialect kept, English terms in
 * Latin script) but its own timestamps drift — measured 2026-10-15, Gemini's
 * segment times were off by a median 3.6 s on 300 s windows and 45 s on 600 s
 * windows — while a word-timestamp ASR has the better CLOCK (~0.4 s median once
 * aligned) and the worse words. So the text comes from one, the times from the
 * other, joined here.
 *
 * HOW (a port of the local hybrid that produced catodemy's 2026-10 Java/Python
 * re-transcription):
 *  1. normalize both token streams (lowercase, no harakat/tatweel, one alef/
 *     yaa/taa-marbuta form, punctuation dropped, a leading «ال» dropped) so the
 *     two spellings of the same spoken word compare equal;
 *  2. find the matching blocks — exactly Python's
 *     difflib.SequenceMatcher(autojunk=False).get_matching_blocks();
 *  3. every matched token takes its ASR word's time (an ANCHOR); the tokens
 *     between two anchors share the gap in proportion to their length, and the
 *     tokens after the last anchor run on at speaking pace (≤ the clip's end);
 *  4. times are made non-decreasing and clamped to [0, clip length];
 *  5. cut a segment after sentence punctuation once it is ≥ 4 s long, after a
 *     comma once ≥ 12 s, unconditionally at 20 s — and at 80 tokens / 600
 *     characters whatever the clock says, so text the clock cannot place (a
 *     repetition loop) still lands in caption-sized segments;
 *  6. a segment squeezed to nothing against the clip's end is folded into the
 *     one before it (or given the minimum length ending at the clip's end).
 *
 * With no words at all every token is interpolated across the clip, so the
 * text survives with coarse times. Pure and stateless: no I/O, no config.
 */
final class TranscriptAligner
{
    private const SENTENCE_END = '/[.!?؟؛]$/u';

    private const CLAUSE_END = '/[،,]$/u';

    private const SENTENCE_MIN_MS = 4_000;

    private const CLAUSE_MIN_MS = 12_000;

    private const SEGMENT_MAX_MS = 20_000;

    private const MIN_SEGMENT_MS = 500;

    private const SEGMENT_MAX_TOKENS = 80;

    /** Speaking pace for text the clock cannot place (~12 characters a second). */
    private const TAIL_MS_PER_CHAR = 80;

    private const SEGMENT_MAX_CHARS = 600;

    /** Per-token duration assumed when there are neither words nor a window length. */
    private const FALLBACK_TOKEN_MS = 400;

    /**
     * @param  list<TimedWord>  $words  the clock: ASR words, ms from the clip start, in time order
     * @param  string  $text  the words: the free-text transcript of the same clip
     * @param  int|null  $clipMs  the clip length — the span the text may occupy, and the clamp
     */
    public function align(array $words, string $text, ?int $clipMs): Alignment
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return new Alignment([], 0.0);
        }

        [$times, $anchored] = $this->tokenTimes($words, $tokens, $clipMs);

        return new Alignment($this->cut($tokens, $times, $clipMs), $anchored / count($tokens));
    }

    /**
     * The normalized comparison form of a token.
     */
    public static function normalize(string $token): string
    {
        $t = mb_strtolower($token);
        $t = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $t);
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $t = (string) preg_replace('/[^\p{L}\p{N}_]/u', '', $t);

        if (str_starts_with($t, 'ال') && mb_strlen($t) > 3) {
            $t = mb_substr($t, 2);
        }

        return $t;
    }

    /**
     * @param  list<TimedWord>  $words
     * @param  list<string>  $tokens
     * @return array{0: list<array{0: int, 1: int}>, 1: int} the per-token [start, end] and the anchor count
     */
    private function tokenTimes(array $words, array $tokens, ?int $clipMs): array
    {
        /** @var array<int, array{0: int, 1: int}> $times */
        $times = [];

        if ($words !== []) {
            $a = array_map(self::normalize(...), $tokens);
            $b = array_map(fn (TimedWord $w): string => self::normalize($w->text), $words);

            foreach ($this->matchingBlocks($a, $b) as [$i, $j, $size]) {
                for ($k = 0; $k < $size; $k++) {
                    if ($a[$i + $k] !== '') {
                        $times[$i + $k] = [$words[$j + $k]->startMs, $words[$j + $k]->endMs];
                    }
                }
            }

            $lo = $words[0]->startMs;
            $hi = $words[array_key_last($words)]->endMs;

            // Text after the last anchor runs on at speaking pace (to the end
            // of the clip at most) instead of piling onto one instant, where no
            // duration cut could ever fire.
            $lastAnchor = $times === [] ? -1 : max(array_keys($times));
            $tailChars = array_sum(array_map(fn (string $t): int => mb_strlen($t) + 1, array_slice($tokens, $lastAnchor + 1)));
            $tailFrom = $lastAnchor >= 0 ? $times[$lastAnchor][1] : $lo;
            $hi = max($hi, min($clipMs ?? PHP_INT_MAX, $tailFrom + $tailChars * self::TAIL_MS_PER_CHAR));
        } else {
            $lo = 0;
            $hi = $clipMs !== null && $clipMs > 0 ? $clipMs : count($tokens) * self::FALLBACK_TOKEN_MS;
        }

        $anchored = count($times);

        ksort($times);

        // Interpolate the unanchored runs between consecutive anchors, by length.
        $points = [-1 => [$lo, $lo]] + $times;
        $points[count($tokens)] = [$hi, $hi];
        $indices = array_keys($points);

        for ($p = 0; $p < count($indices) - 1; $p++) {
            $i0 = $indices[$p];
            $i1 = $indices[$p + 1];

            if ($i1 - $i0 <= 1) {
                continue;
            }

            $from = $points[$i0][1];
            $to = max($from, $points[$i1][0]);
            $span = array_slice($tokens, $i0 + 1, $i1 - $i0 - 1);
            $total = array_sum(array_map(fn (string $t): int => mb_strlen($t) + 1, $span));
            $acc = 0;

            foreach ($span as $k => $token) {
                $start = $from + intdiv(($to - $from) * $acc, $total);
                $acc += mb_strlen($token) + 1;
                $times[$i0 + 1 + $k] = [$start, $from + intdiv(($to - $from) * $acc, $total)];
            }
        }

        ksort($times);

        // Non-decreasing, inside the clip: an ASR that reorders or overlaps
        // words, or stamps one past the clip's end, never yields a cue that
        // runs backwards or outside its window.
        $ceiling = $clipMs !== null && $clipMs > 0 ? $clipMs : PHP_INT_MAX;
        $floor = 0;
        $out = [];

        foreach (array_values($times) as [$start, $end]) {
            $start = min(max($start, $floor), $ceiling);
            $end = min(max($end, $start), $ceiling);
            $out[] = [$start, $end];
            $floor = $start;
        }

        return [$out, $anchored];
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<array{0: int, 1: int}>  $times
     * @return list<AlignedSegment>
     */
    private function cut(array $tokens, array $times, ?int $clipMs): array
    {
        $segments = [];
        $current = [];
        $chars = 0;

        foreach ($tokens as $i => $token) {
            // The character cap cuts BEFORE the token that would cross it.
            if ($current !== [] && $chars + mb_strlen($token) > self::SEGMENT_MAX_CHARS) {
                $segments[] = $current;
                $current = [];
                $chars = 0;
            }

            $current[] = $i;
            $chars += mb_strlen($token) + 1;
            $duration = $times[$i][1] - $times[$current[0]][0];

            if ((preg_match(self::SENTENCE_END, $token) === 1 && $duration >= self::SENTENCE_MIN_MS)
                || (preg_match(self::CLAUSE_END, $token) === 1 && $duration >= self::CLAUSE_MIN_MS)
                || $duration >= self::SEGMENT_MAX_MS
                || count($current) >= self::SEGMENT_MAX_TOKENS) {
                $segments[] = $current;
                $current = [];
                $chars = 0;
            }
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        $ceiling = $clipMs !== null && $clipMs > 0 ? $clipMs : PHP_INT_MAX;
        $out = [];

        foreach ($segments as $indices) {
            $start = $times[$indices[0]][0];
            $last = $indices[array_key_last($indices)];
            $text = implode(' ', array_map(fn (int $i): string => $tokens[$i], $indices));
            $end = min(max($times[$last][1], $start + self::MIN_SEGMENT_MS), max($ceiling, $start));

            // Squeezed against the clip's end (start == end once clamped): a
            // zero-length cue is invalid and a player would stretch it past
            // the window. Fold it into the previous segment when that stays
            // within the caps; otherwise give it the minimum length that still
            // ends at the clip's end.
            if ($end - $start < self::MIN_SEGMENT_MS) {
                $previous = $out === [] ? null : $out[array_key_last($out)];

                if ($previous !== null
                    && count(preg_split('/\s+/u', $previous->text) ?: []) + count($indices) <= self::SEGMENT_MAX_TOKENS
                    && mb_strlen($previous->text) + 1 + mb_strlen($text) <= self::SEGMENT_MAX_CHARS) {
                    $out[array_key_last($out)] = new AlignedSegment($previous->startMs, max($previous->endMs, $end), $previous->text.' '.$text);

                    continue;
                }

                $start = max($previous->startMs ?? 0, min($start, $end - self::MIN_SEGMENT_MS), 0);
            }

            $out[] = new AlignedSegment($start, $end, $text);
        }

        return $out;
    }

    /**
     * Python's difflib.SequenceMatcher(None, a, b, autojunk=False)
     * .get_matching_blocks(), minus the zero-length sentinel.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: int, 2: int}> [i, j, size], ascending, adjacent blocks merged
     */
    private function matchingBlocks(array $a, array $b): array
    {
        /** @var array<string, list<int>> $b2j */
        $b2j = [];
        foreach ($b as $j => $token) {
            $b2j[$token][] = $j;
        }

        $blocks = [];
        $queue = [[0, count($a), 0, count($b)]];

        while ($queue !== []) {
            [$alo, $ahi, $blo, $bhi] = array_pop($queue);
            [$i, $j, $k] = $this->longestMatch($a, $b, $b2j, $alo, $ahi, $blo, $bhi);

            if ($k > 0) {
                $blocks[] = [$i, $j, $k];

                if ($alo < $i && $blo < $j) {
                    $queue[] = [$alo, $i, $blo, $j];
                }

                if ($i + $k < $ahi && $j + $k < $bhi) {
                    $queue[] = [$i + $k, $ahi, $j + $k, $bhi];
                }
            }
        }

        sort($blocks);

        $merged = [];
        foreach ($blocks as [$i, $j, $k]) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last][0] + $merged[$last][2] === $i && $merged[$last][1] + $merged[$last][2] === $j) {
                $merged[$last][2] += $k;

                continue;
            }

            $merged[] = [$i, $j, $k];
        }

        return $merged;
    }

    /**
     * difflib's find_longest_match with no junk: the longest common run of
     * a[alo:ahi] and b[blo:bhi], earliest in a, then earliest in b.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  array<string, list<int>>  $b2j
     * @return array{0: int, 1: int, 2: int}
     */
    private function longestMatch(array $a, array $b, array $b2j, int $alo, int $ahi, int $blo, int $bhi): array
    {
        [$besti, $bestj, $bestsize] = [$alo, $blo, 0];
        $j2len = [];

        for ($i = $alo; $i < $ahi; $i++) {
            $next = [];

            foreach ($b2j[$a[$i]] ?? [] as $j) {
                if ($j < $blo) {
                    continue;
                }

                if ($j >= $bhi) {
                    break;
                }

                $k = $next[$j] = ($j2len[$j - 1] ?? 0) + 1;

                if ($k > $bestsize) {
                    [$besti, $bestj, $bestsize] = [$i - $k + 1, $j - $k + 1, $k];
                }
            }

            $j2len = $next;
        }

        while ($besti > $alo && $bestj > $blo && $a[$besti - 1] === $b[$bestj - 1]) {
            [$besti, $bestj, $bestsize] = [$besti - 1, $bestj - 1, $bestsize + 1];
        }

        while ($besti + $bestsize < $ahi && $bestj + $bestsize < $bhi && $a[$besti + $bestsize] === $b[$bestj + $bestsize]) {
            $bestsize++;
        }

        return [$besti, $bestj, $bestsize];
    }
}

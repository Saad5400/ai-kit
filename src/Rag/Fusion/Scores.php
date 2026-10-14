<?php

namespace Saad\AiKit\Rag\Fusion;

/**
 * The one deterministic ordering every fusion function returns: score
 * descending, then key ascending (`<=>`, so integer ids compare
 * numerically) — two runs over the same inputs never disagree on a tie.
 */
final class Scores
{
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, float>  $scores
     * @return array<TKey, float>
     */
    public static function sort(array $scores): array
    {
        uksort($scores, static fn (int|string $a, int|string $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));

        return $scores;
    }

    /**
     * The keys of {@see sort()}, best first.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, float>  $scores
     * @return list<TKey>
     */
    public static function keys(array $scores): array
    {
        return array_keys(self::sort($scores));
    }
}

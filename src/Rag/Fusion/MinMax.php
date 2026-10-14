<?php

namespace Saad\AiKit\Rag\Fusion;

/**
 * Min-max score normalisation into [0, 1] — what makes raw scores from
 * different retrievers (a ts_rank, a cosine, a rerank score) addable.
 *
 * Pass `$floor` for THEORETICAL min-max (Bruch et al. 2023): the known lower
 * bound of the scorer (0 for a clamped cosine) replaces the observed minimum,
 * so a list whose hits are all mediocre is not stretched to look perfect.
 */
final class MinMax
{
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, float>  $scores
     * @return array<TKey, float> same keys, same order, values in [0, 1]
     */
    public static function normalize(array $scores, ?float $floor = null): array
    {
        if ($scores === []) {
            return [];
        }

        $max = max($scores);
        $min = $floor ?? min($scores);
        $span = $max - $min;

        $normalized = [];

        foreach ($scores as $key => $score) {
            // Every score equal (or a single hit): they are all the list's
            // best, so they all get the top of the scale.
            $normalized[$key] = $span <= 0.0 ? 1.0 : max(0.0, min(1.0, ($score - $min) / $span));
        }

        return $normalized;
    }
}

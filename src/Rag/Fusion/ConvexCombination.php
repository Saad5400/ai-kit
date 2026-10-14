<?php

namespace Saad\AiKit\Rag\Fusion;

/**
 * Normalised weighted fusion: score(d) = Σ_lists weight · norm(score_list(d)),
 * a key missing from a list contributing 0 there. With labels to tune the
 * weights on, this is what Bruch et al. (2023) found to beat RRF; without
 * them, prefer {@see Rrf}.
 */
final class ConvexCombination
{
    /**
     * @param  array<array-key, array<array-key, float>>  $scoresByList  list name => (key => raw score)
     * @param  array<array-key, float>  $weights  list name => weight (a missing name weighs 1.0)
     * @param  array<array-key, float|null>  $floors  list name => theoretical minimum for {@see MinMax}
     * @return array<array-key, float> key => fused score, best first
     */
    public static function scores(array $scoresByList, array $weights = [], bool $normalize = true, array $floors = []): array
    {
        $fused = [];

        foreach ($scoresByList as $name => $scores) {
            $weight = (float) ($weights[$name] ?? 1.0);
            $values = $normalize ? MinMax::normalize($scores, $floors[$name] ?? null) : $scores;

            foreach ($values as $key => $value) {
                $fused[$key] = ($fused[$key] ?? 0.0) + $weight * (float) $value;
            }
        }

        return Scores::sort($fused);
    }

    /**
     * The fused keys, best first.
     *
     * @param  array<array-key, array<array-key, float>>  $scoresByList
     * @param  array<array-key, float>  $weights
     * @param  array<array-key, float|null>  $floors
     * @return list<array-key>
     */
    public static function fuse(array $scoresByList, array $weights = [], bool $normalize = true, array $floors = []): array
    {
        return array_keys(self::scores($scoresByList, $weights, $normalize, $floors));
    }
}

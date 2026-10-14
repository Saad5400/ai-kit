<?php

namespace Saad\AiKit\Rag\Fusion;

/**
 * Reciprocal Rank Fusion: score(d) = Σ_lists weight / (k + rank), rank
 * 1-based. Needs no labels and no score calibration, which is why it is the
 * default way to merge a lexical and a semantic candidate list.
 *
 * Pure statics over KEYS (chunk ids, refs — any array-key), so an app fuses
 * its own rows by mapping them to keys first and back afterwards. A key that
 * appears twice in one list is counted once, at its best rank.
 */
final class Rrf
{
    /** The conventional constant (Cormack et al.; Elastic, Vespa, OpenSearch default). */
    public const K = 60;

    /**
     * @param  array<array-key, list<array-key>>  $rankings  list name => keys, best first
     * @param  array<array-key, float>  $weights  list name => weight (a missing name weighs 1.0)
     * @return array<array-key, float> key => fused score, best first (see {@see Scores::sort()})
     */
    public static function scores(array $rankings, int $k = self::K, array $weights = []): array
    {
        $scores = [];

        foreach ($rankings as $name => $keys) {
            $weight = (float) ($weights[$name] ?? 1.0);
            $seen = [];
            $rank = 0;

            foreach ($keys as $key) {
                $rank++;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $scores[$key] = ($scores[$key] ?? 0.0) + self::contribution($rank, $k, $weight);
            }
        }

        return Scores::sort($scores);
    }

    /**
     * The fused keys, best first.
     *
     * @param  array<array-key, list<array-key>>  $rankings
     * @param  array<array-key, float>  $weights
     * @return list<array-key>
     */
    public static function fuse(array $rankings, int $k = self::K, array $weights = []): array
    {
        return array_keys(self::scores($rankings, $k, $weights));
    }

    /** One list's contribution for a 1-based rank: weight / (k + rank). */
    public static function contribution(int $rank, int $k = self::K, float $weight = 1.0): float
    {
        return $weight * (1.0 / ($k + max(1, $rank)));
    }
}

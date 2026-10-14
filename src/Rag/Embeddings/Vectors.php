<?php

namespace Saad\AiKit\Rag\Embeddings;

/** Pure vector helpers the drivers share. */
final class Vectors
{
    /**
     * Scale to unit length, so cosine similarity equals the dot product. A
     * zero vector stays zero.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }

    /**
     * Matryoshka truncation: keep the first `$dimensions` components and
     * re-normalise. Only meaningful for a model trained for it (MRL) — for
     * any other model a prefix is not an embedding.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function truncate(array $vector, int $dimensions): array
    {
        return count($vector) <= $dimensions
            ? $vector
            : self::normalize(array_slice($vector, 0, $dimensions));
    }

    /**
     * Cosine similarity of two equal-length vectors (0 when either is zero).
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $na += $value * $value;
            $nb += $other * $other;
        }

        return ($na == 0.0 || $nb == 0.0) ? 0.0 : $dot / (sqrt($na) * sqrt($nb));
    }
}

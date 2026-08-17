<?php

namespace Saad\AiKit\Support;

/**
 * Deterministic uuid-shaped ids derived from a main turn id, so a pre-pass
 * (vision extraction, per-batch document OCR) gets its own idempotent
 * usage-event row and credit charge that can never collide with the main
 * turn's `debit:turn:{id}` — and a retried job re-derives the same ids
 * instead of re-billing. Suffix conventions: 'vision',
 * 'document:{fileIndex}:{batchIndex}'.
 */
final class DerivedTurnId
{
    public static function for(string $turnId, string $suffix): string
    {
        $hash = hash('sha256', $turnId.':'.$suffix);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }
}

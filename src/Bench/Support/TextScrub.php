<?php

namespace Saad\AiKit\Bench\Support;

/**
 * Text helpers the graders share: strip code and URLs before a language
 * or placeholder check, count scripts, find glued Latin+Arabic tokens.
 */
final class TextScrub
{
    /** Remove fenced + inline code and URLs — neither says anything about the reply's language. */
    public static function stripCodeAndUrls(string $text): string
    {
        $text = preg_replace('/```.*?```/su', ' ', $text) ?? $text;
        $text = preg_replace('/`[^`\n]*`/u', ' ', $text) ?? $text;
        $text = preg_replace('~https?://\S+~iu', ' ', $text) ?? $text;
        $text = preg_replace('/\S+@\S+\.\S+/u', ' ', $text) ?? $text;

        return $text;
    }

    public static function arabicLetters(string $text): int
    {
        return preg_match_all('/\p{Arabic}/u', $text);
    }

    public static function latinLetters(string $text): int
    {
        return preg_match_all('/\p{Latin}/u', $text);
    }

    /**
     * Tokens where Latin letters are glued to Arabic letters with no
     * separator ("Letني", "الcourse") — the signature of a language slip.
     *
     * @return list<string>
     */
    public static function gluedScripts(string $text): array
    {
        preg_match_all('/\S*(?:(?=\p{L})\p{Latin}(?=\p{L})\p{Arabic}|(?=\p{L})\p{Arabic}(?=\p{L})\p{Latin})\S*/u', $text, $matches);

        // One-letter Arabic proclitics attach to the following word without a
        // space — «وCLOs» (and CLOs), «بPython» (in Python) — so such a prefix on a
        // Latin token is correct typography, not a slip. The article («الcourse»)
        // or an Arabic suffix on a Latin word («Letني») is one.
        $tokens = array_filter($matches[0], function (string $token): bool {
            $core = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token) ?? $token;

            return preg_match('/^[وفبلك]\p{Latin}[\p{Latin}\p{N}\p{P}\p{S}]*$/u', $core) !== 1;
        });

        return array_values(array_unique($tokens));
    }

    public static function excerpt(?string $text, int $length = 160): string
    {
        $text = trim((string) $text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'…';
    }
}

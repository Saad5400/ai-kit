<?php

namespace Saad\AiKit\Rag\Text;

/**
 * Wrong-layout repair between a US (QWERTY) keyboard and the Windows / macOS
 * "Arabic (101)" layout: a student who forgot to switch types
 * `hda hgyg' td hgw,vi` meaning «ايش الغلط في الصوره», or `اثممخ` meaning
 * `hello`.
 *
 * This only TRANSLITERATES keystrokes; deciding whether the result is what
 * the user meant (e.g. by its in-vocabulary rate) is the caller's job, so a
 * genuine English query is never rewritten blindly. Characters with no key
 * mapping pass through untouched.
 *
 * Arabic → Latin is resolved one character at a time, so «لا» becomes `gh`
 * (two keystrokes) rather than the single lam-alef key `b` — the more common
 * way an English word ends up typed as «لا».
 */
final class KeyboardLayout
{
    /**
     * Key → Arabic (101). Unshifted keys, plus the shifted keys that produce
     * an Arabic letter, hamza form or mark (not the ones that produce ASCII
     * punctuation, which would make the reverse direction ambiguous).
     *
     * @var array<string, string>
     */
    public const LATIN_TO_ARABIC = [
        '`' => 'ذ',
        'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف', 'y' => 'غ', 'u' => 'ع',
        'i' => 'ه', 'o' => 'خ', 'p' => 'ح', '[' => 'ج', ']' => 'د',
        'a' => 'ش', 's' => 'س', 'd' => 'ي', 'f' => 'ب', 'g' => 'ل', 'h' => 'ا', 'j' => 'ت',
        'k' => 'ن', 'l' => 'م', ';' => 'ك', '\'' => 'ط',
        'z' => 'ئ', 'x' => 'ء', 'c' => 'ؤ', 'v' => 'ر', 'b' => 'لا', 'n' => 'ى', 'm' => 'ة',
        ',' => 'و', '.' => 'ز', '/' => 'ظ',
        // Shifted.
        '~' => 'ّ', 'Q' => 'َ', 'W' => 'ً', 'E' => 'ُ', 'R' => 'ٌ', 'T' => 'لإ', 'Y' => 'إ',
        'A' => 'ِ', 'S' => 'ٍ', 'G' => 'لأ', 'H' => 'أ', 'J' => 'ـ', 'K' => '،', 'P' => '؛',
        'X' => 'ْ', 'B' => 'لآ', 'N' => 'آ', '?' => '؟',
    ];

    /** Keystrokes typed on the US layout, read as if the Arabic layout was on. */
    public static function toArabic(string $text): string
    {
        $out = '';

        foreach (mb_str_split($text) as $char) {
            $out .= self::LATIN_TO_ARABIC[$char] ?? $char;
        }

        return $out;
    }

    /** Keystrokes typed on the Arabic layout, read as if the US layout was on. */
    public static function toLatin(string $text): string
    {
        $map = self::arabicToLatin();
        $out = '';

        foreach (mb_str_split($text) as $char) {
            $out .= $map[$char] ?? $char;
        }

        return $out;
    }

    /**
     * The single-character inverse of {@see LATIN_TO_ARABIC} (the lam-alef
     * ligature keys are skipped — see the class docblock).
     *
     * @return array<string, string>
     */
    private static function arabicToLatin(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::LATIN_TO_ARABIC as $key => $arabic) {
                if (mb_strlen($arabic) === 1) {
                    $map[$arabic] ??= (string) $key;
                }
            }
        }

        return $map;
    }
}

<?php

namespace Saad\AiKit\Conversations;

/**
 * The language the user's message is written in, judged by script — and the
 * one-line context hint that tells the model to answer in it.
 *
 * Small chat models drift toward the language of the tool results (an English
 * message about an Arabic-named course comes back in Arabic, and vice versa)
 * even when the system prompt says to follow the user. A per-turn hint in the
 * user envelope ({@see UserPromptEnvelope}) fixes that deterministically while
 * leaving the system prompt byte-identical for the provider's prompt cache.
 * Mixed-script messages and one-letter keystrokes get no hint — either
 * language is a fair reply there.
 */
final class ReplyLanguage
{
    /** Below this many letters a message says nothing about its language. */
    public const MIN_LETTERS = 3;

    /** The share of letters one script needs to count as the message's language. */
    public const DOMINANCE = 0.55;

    /**
     * 'ar' or 'en' when one script clearly dominates the letters, else null.
     */
    public static function detect(string $text): ?string
    {
        $text = (string) preg_replace('~https?://\S+|`[^`]*`~u', ' ', $text);

        $arabic = (int) preg_match_all('/\p{Arabic}/u', $text);
        $latin = (int) preg_match_all('/\p{Latin}/u', $text);
        $total = $arabic + $latin;

        if ($total < self::MIN_LETTERS) {
            return null;
        }

        if ($arabic / $total >= self::DOMINANCE) {
            return 'ar';
        }

        if ($latin / $total >= self::DOMINANCE) {
            return 'en';
        }

        return null;
    }

    /**
     * The context line to put in the envelope, or null when no hint applies.
     */
    public static function hint(string $text): ?string
    {
        return match (self::detect($text)) {
            'ar' => 'The user wrote in Arabic — reply in Arabic (names, codes and identifiers may stay in Latin script).',
            'en' => 'The user wrote in English — reply in English (Arabic record names may be quoted as they are).',
            default => null,
        };
    }
}

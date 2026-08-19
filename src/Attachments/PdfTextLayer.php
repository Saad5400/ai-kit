<?php

namespace Saad\AiKit\Attachments;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The born-digital decision, extracted as the single source of truth so an
 * assistant fast path and any pre-spend probe can never disagree: a PDF
 * whose text layer is real gets parsed for free; a scanned or garbled one
 * routes to vision/OCR. "Garbled" includes Arabic that comes back in visual
 * order — see {@see isReversedArabic()}, the one form of corruption that
 * looks flawless to every other check here.
 *
 * Poppler is strictly best-effort — a missing binary, non-zero exit, or
 * timeout reads as "no text layer" (null), never an exception, so the local
 * fast path stays a pure optimisation and vision remains the fallback.
 */
class PdfTextLayer
{
    /**
     * The Arabic function words whose ORDER betrays a visual-order text
     * layer. Short, unavoidable in any real Arabic prose, and — the property
     * that makes this a decision rather than a guess — not one of their
     * reversals is itself an Arabic word, so a standalone `يف` can only be a
     * `في` that came out backwards.
     *
     * The ligature-bearing entries (`إلى`, `على`, `التي`) are the weak ones:
     * real poppler output scrambles a lam-alef internally, so `إلى` can land
     * as `ىإل` rather than the clean reversal. They are kept because they
     * cost nothing, but the two-letter words are what actually fires.
     *
     * @var list<string>
     */
    public const ARABIC_FUNCTION_WORDS = ['في', 'من', 'على', 'إلى', 'عن', 'أن', 'هذا', 'التي'];

    /**
     * Arabic letters as a share of ALL letters — digits, punctuation and
     * whitespace are script-neutral and excluded, so a table of numbers
     * cannot dilute the reading of its own headings. 0.60 keeps the probe
     * off Latin documents that merely quote some Arabic, while still firing
     * on the mixed Arabic/English reports these PDFs actually are.
     */
    public const MIN_ARABIC_LETTER_RATIO = 0.60;

    /**
     * Below this many letters both the script ratio and the word counts are
     * noise: a mostly-numeric table with two Arabic headings is not evidence
     * of an Arabic document, in either order.
     */
    public const MIN_LETTERS_TO_PROBE = 40;

    /**
     * Reversed hits needed before the probe speaks at all. One is a
     * coincidence, and the cost of a false positive is a paid vision call on
     * a PDF we could have read for free.
     */
    public const MIN_REVERSED_HITS = 2;

    /**
     * How far reversed hits must outnumber normal ones to read as visual
     * order. This is the rule for text that shows BOTH orders — a document
     * whose pages did not all come out the same way — where 3x is
     * deliberately strict: half-reversed text still reroutes only when the
     * reversed half plainly owns the page.
     */
    public const REVERSED_DOMINANCE = 3.0;

    /**
     * The other way in, and the one real visual-order output takes: normal
     * forms all but absent. Wholly reversed text has NO normal-order
     * function words at all, so it never needs to clear the ratio above —
     * one stray normal hit must not save it.
     */
    public const NEAR_ABSENT_NORMAL_HITS = 1;

    public function __construct(
        protected int $minCharsPerPage = 80,
        protected float $maxJunkRatio = 0.10,
        protected int $timeoutSeconds = 60,
        protected string $pdftotextBinary = 'pdftotext',
        protected string $pdfinfoBinary = 'pdfinfo',
    ) {}

    /**
     * The text layer when it passes the born-digital thresholds, else null.
     */
    public function text(string $absolutePath, ?int $pageCount = null): ?string
    {
        $raw = $this->raw($absolutePath);

        if ($raw === null) {
            return null;
        }

        $text = trim($this->stripControlJunk($raw));

        if ($text === '') {
            return null;
        }

        $pageCount ??= $this->pageCount($absolutePath);

        if (mb_strlen($text) < $this->minCharsPerPage * max(1, $pageCount ?? 1)) {
            return null;
        }

        if ($this->junkRatio($raw) > $this->maxJunkRatio) {
            return null;
        }

        if ($this->isReversedArabic($text)) {
            return null;
        }

        return $text;
    }

    /**
     * The raw pdftotext output with no thresholds applied — for callers
     * that just want whatever text exists (an empty layer reads as null).
     */
    public function raw(string $absolutePath): ?string
    {
        return $this->run([$this->pdftotextBinary, '-layout', '-q', '-enc', 'UTF-8', $absolutePath, '-']);
    }

    public function pageCount(string $absolutePath): ?int
    {
        $output = $this->run([$this->pdfinfoBinary, $absolutePath]);

        if ($output !== null && preg_match('/^Pages:\s+(\d+)/m', $output, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Drop the bidi/zero-width controls pdftotext emits around Arabic runs,
     * so the length threshold measures real content.
     */
    public function stripControlJunk(string $text): string
    {
        return (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);
    }

    /**
     * Replacement characters and stray control bytes as a share of the
     * output — a proxy for a garbled text layer. Blind to text that is
     * garbled by ORDER alone; {@see isReversedArabic()} covers that.
     */
    public function junkRatio(string $text): float
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 0.0;
        }

        $junk = preg_match_all('/[\x{FFFD}\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text);

        return ($junk === false ? 0 : $junk) / $length;
    }

    /**
     * The reversal probe. Poppler hands back some Arabic born-digital PDFs
     * in VISUAL order: the words of a line come out backwards and each
     * word's codepoints are scrambled into display order, so `في` arrives as
     * `يف`. That text scores a perfect {@see junkRatio()} — every codepoint
     * IS valid Arabic, only the order is wrong — so nothing else in the
     * born-digital decision can catch it. A positive reads as "no usable
     * text layer" and {@see ExtractionRouter} takes the very same vision
     * branch a scanned PDF takes. Detection and rerouting only: bidi
     * reordering is the vision model's job, not PHP's.
     */
    public function isReversedArabic(string $text): bool
    {
        $letters = $this->count('/\p{L}/u', $text);

        if ($letters < self::MIN_LETTERS_TO_PROBE) {
            return false;
        }

        if ($this->arabicLetterRatio($text) < self::MIN_ARABIC_LETTER_RATIO) {
            return false;
        }

        $reversed = $this->functionWordHits($text, array_map(
            fn (string $word): string => implode('', array_reverse(mb_str_split($word))),
            self::ARABIC_FUNCTION_WORDS,
        ));

        if ($reversed < self::MIN_REVERSED_HITS) {
            return false;
        }

        $normal = $this->functionWordHits($text, self::ARABIC_FUNCTION_WORDS);

        return $reversed >= self::REVERSED_DOMINANCE * $normal
            || $normal <= self::NEAR_ABSENT_NORMAL_HITS;
    }

    /**
     * Arabic-block letters as a share of all letters — see
     * {@see MIN_ARABIC_LETTER_RATIO} for why digits are not in the
     * denominator. The lookahead is what keeps Arabic-Indic digits out of
     * the numerator too: `\p{Arabic}` alone would count `٤` as script
     * evidence while `\p{L}` rightly refuses to count it as a letter.
     */
    public function arabicLetterRatio(string $text): float
    {
        $letters = $this->count('/\p{L}/u', $text);

        if ($letters === 0) {
            return 0.0;
        }

        return $this->count('/(?=\p{L})\p{Arabic}/u', $text) / $letters;
    }

    /**
     * Standalone occurrences of any of the given words. The guards are
     * explicit rather than `\b`, which knows nothing of Arabic: a word only
     * counts when neither neighbour is a letter or a combining mark, so the
     * `من` inside `مناسب` is not a hit.
     *
     * @param  list<string>  $words
     */
    protected function functionWordHits(string $text, array $words): int
    {
        $alternatives = implode('|', array_map(
            fn (string $word): string => preg_quote($word, '/'),
            $words,
        ));

        return $this->count('/(?<![\p{L}\p{M}])(?:'.$alternatives.')(?![\p{L}\p{M}])/u', $text);
    }

    protected function count(string $pattern, string $text): int
    {
        $matches = preg_match_all($pattern, $text);

        return $matches === false ? 0 : $matches;
    }

    /**
     * @param  list<string>  $command
     */
    protected function run(array $command): ?string
    {
        try {
            $process = new Process($command, timeout: $this->timeoutSeconds);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }
}

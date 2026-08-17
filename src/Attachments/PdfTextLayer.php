<?php

namespace Saad\AiKit\Attachments;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The born-digital decision, extracted as the single source of truth so an
 * assistant fast path and any pre-spend probe can never disagree: a PDF
 * whose text layer is real gets parsed for free; a scanned or garbled one
 * routes to vision/OCR.
 *
 * Poppler is strictly best-effort — a missing binary, non-zero exit, or
 * timeout reads as "no text layer" (null), never an exception, so the local
 * fast path stays a pure optimisation and vision remains the fallback.
 */
class PdfTextLayer
{
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
     * output — a proxy for a garbled text layer.
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

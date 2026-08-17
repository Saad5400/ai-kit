<?php

namespace Saad\AiKit\Attachments;

/**
 * The extraction split all three apps converged on, with the cost lesson
 * baked in (a PDF that rides along as a live attachment gets re-parsed by
 * the provider on EVERY tool-call round — extract once, inject text):
 *
 *  - image/* → vision (the app's vision agent reads it, once);
 *  - PDF → the born-digital decision: a real text layer parses for free,
 *    a scanned/garbled one routes to vision;
 *  - OOXML/HTML/plain formats → free local parse ('' → skip);
 *  - anything else → skip (no OCR of archives or binaries).
 *
 * Text results ride the {@see ExtractionCache} so identical bytes never
 * re-parse; vision routing is cached only as a decision by virtue of the
 * text miss being cheap to re-decide (the vision CALL is the app's to
 * cache or meter).
 */
class ExtractionRouter
{
    public function __construct(
        protected PdfTextLayer $pdf,
        protected LocalTextExtractor $local,
        protected ExtractionCache $cache,
    ) {}

    public function route(string $absolutePath, string $mime, ?string $extension = null): Extraction
    {
        if (str_starts_with($mime, 'image/')) {
            return Extraction::vision();
        }

        $extension = strtolower($extension ?? pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            $text = $this->cache->remember($absolutePath, fn (): ?string => $this->pdf->text($absolutePath));

            return $text === null ? Extraction::vision() : Extraction::text($text);
        }

        $text = trim((string) $this->cache->remember(
            $absolutePath,
            fn (): string => $this->local->extract($absolutePath, $extension),
        ));

        return $text === '' ? Extraction::skip() : Extraction::text($text);
    }
}

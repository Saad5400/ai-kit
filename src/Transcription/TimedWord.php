<?php

namespace Saad\AiKit\Transcription;

/**
 * One word a speech-to-text model heard and when (ms from the start of the
 * clip it was transcribed from) — the CLOCK half of a transcript that
 * {@see TranscriptAligner} lays another model's words onto.
 */
final readonly class TimedWord
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {}
}

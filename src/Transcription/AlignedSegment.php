<?php

namespace Saad\AiKit\Transcription;

/** One sentence segment of an aligned transcript, ms from the clip start. */
final readonly class AlignedSegment
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {}
}

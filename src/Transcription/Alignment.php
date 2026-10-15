<?php

namespace Saad\AiKit\Transcription;

/**
 * The result of {@see TranscriptAligner::align()}: the sentence segments and
 * how much of the text was anchored to the clock (0..1) — the alignment's health.
 */
final readonly class Alignment
{
    /**
     * @param  list<AlignedSegment>  $segments
     */
    public function __construct(
        public array $segments,
        public float $anchorRate,
    ) {}
}

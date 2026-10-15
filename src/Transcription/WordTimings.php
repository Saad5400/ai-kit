<?php

namespace Saad\AiKit\Transcription;

/**
 * What {@see WordTimingClient} heard in one clip: the timed words, the ASR's
 * own plain text, the provider-reported cost and the model that answered.
 * An empty `words` list with empty `text` is silence; empty `words` with text
 * means the model ignored the word-timestamp request.
 */
final readonly class WordTimings
{
    /**
     * @param  list<TimedWord>  $words
     */
    public function __construct(
        public array $words,
        public string $text,
        public ?float $costUsd,
        public string $model,
    ) {}

    /** Nothing was heard at all. */
    public function silent(): bool
    {
        return $this->words === [] && trim($this->text) === '';
    }
}

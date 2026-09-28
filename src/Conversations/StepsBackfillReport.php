<?php

namespace Saad\AiKit\Conversations;

/**
 * What one {@see StepsBackfill} pass did: rows converted onto steps, paused
 * rows re-derived from results an old worker recorded afterwards, and the
 * rows it left untouched because a column would not decrypt.
 */
final class StepsBackfillReport
{
    /**
     * @param  list<string>  $undecryptable  message ids left untouched (unconverted ones keep `steps` NULL)
     */
    public function __construct(
        public int $written = 0,
        public int $reconciled = 0,
        public array $undecryptable = [],
    ) {}

    public function skip(string $messageId): void
    {
        if (! in_array($messageId, $this->undecryptable, true)) {
            $this->undecryptable[] = $messageId;
        }
    }

    public function hasUndecryptable(): bool
    {
        return $this->undecryptable !== [];
    }

    /**
     * One line naming the undecryptable rows (the first $limit ids).
     */
    public function undecryptableSummary(int $limit = 50): string
    {
        $ids = array_slice($this->undecryptable, 0, $limit);
        $more = count($this->undecryptable) - count($ids);

        return sprintf(
            '%d conversation message(s) left untouched: they (or a row their tool calls were answered on) hold ciphertext this app key cannot decrypt — APP_KEY rotated without APP_PREVIOUS_KEYS? Restore the key and re-run ai-kit:backfill-conversation-steps. Ids: %s%s',
            count($this->undecryptable),
            implode(', ', $ids),
            $more > 0 ? sprintf(' … and %d more', $more) : '',
        );
    }
}

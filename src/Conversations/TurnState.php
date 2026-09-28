<?php

namespace Saad\AiKit\Conversations;

use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Storage\StoredMessage;
use Saad\AiKit\Streaming\TurnCancelledException;

/**
 * How a stored assistant turn ended, for an app rendering history: 1.0's
 * `status` column plus the one distinction it does not make — a turn the
 * user STOPPED is stored as `failed` with
 * `meta.error === TurnCancelledException::MESSAGE`, and an app shows it as
 * "stopped", not as an error with a retry button.
 *
 * Reads the kit's rows as the store writes them: `meta` may be a decoded
 * array (a StoredMessage, the vendor model's cast), a raw column value —
 * sealed or plaintext JSON — or null. With tool traces off the store still
 * keeps a failed turn's `error`, so a stop stays recognisable.
 */
enum TurnState: string
{
    case Completed = 'completed';
    case Paused = 'paused';
    case Failed = 'failed';
    case Stopped = 'stopped';

    /**
     * Classify a stored assistant row from its `status` and `meta`.
     *
     * @param  array<string, mixed>|string|null  $meta  decoded meta, or the raw (possibly sealed) column
     */
    public static function of(MessageStatus|string $status, array|string|null $meta = null): self
    {
        $status = $status instanceof MessageStatus ? $status : MessageStatus::from($status);

        return match ($status) {
            MessageStatus::Completed => self::Completed,
            MessageStatus::Paused => self::Paused,
            MessageStatus::Failed => TurnCancelledException::marks(is_array($meta) ? $meta : ConversationContent::revealJson($meta))
                ? self::Stopped
                : self::Failed,
        };
    }

    /**
     * Classify a message the store handed out (paginateConversationMessages()).
     */
    public static function ofMessage(StoredMessage $message): self
    {
        return self::of($message->status, $message->meta);
    }

    /**
     * Whether the turn did not finish — failed or stopped.
     */
    public function interrupted(): bool
    {
        return $this === self::Failed || $this === self::Stopped;
    }
}

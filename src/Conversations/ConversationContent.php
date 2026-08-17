<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The read seam for message content that did not travel through the store.
 *
 * EncryptedConversationStore only decrypts on its own read path (loading
 * context for a turn). Apps that read `ConversationMessage` rows directly —
 * rehydrating a chat panel, exporting a thread — must pass `content` through
 * reveal(), which decrypts ciphertext and passes plaintext through untouched,
 * so the same call site works before encryption was enabled, after, and with
 * it off entirely.
 */
class ConversationContent
{
    public static function reveal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }
}

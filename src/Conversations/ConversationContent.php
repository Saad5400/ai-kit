<?php

namespace Saad\AiKit\Conversations;

use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Contracts\ConversationStore;
use Throwable;

/**
 * The one place message columns are sealed and opened at rest.
 *
 * EncryptedConversationStore only decrypts on its own read paths (loading
 * context for a turn, pendingApprovalsFor(), paginateConversationMessages()).
 * Apps that read `ConversationMessage` rows directly — rehydrating a chat
 * panel, exporting a thread — must pass `content` through reveal() and the
 * JSON columns (`steps`, `meta`, `attachments`) through revealJson(), which
 * decrypt ciphertext and pass plaintext through untouched, so the same call
 * site works before encryption was enabled, after, and with it off entirely.
 * (The vendor model's `array` casts cannot read ciphertext: they yield null.)
 *
 * conceal() / concealJson() are the write half the store, the steps
 * backfill and the prune command share, so every writer seals a column the
 * same way.
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

    /**
     * Reveal a value, refusing to pass ciphertext off as plaintext: a value
     * shaped like a Laravel encrypted payload that does NOT decrypt (the app
     * key rotated without `APP_PREVIOUS_KEYS`, a foreign key, a corrupt
     * row) throws instead of coming back as-is. For writers that would
     * otherwise persist the ciphertext as text, or overwrite a column they
     * could not read.
     *
     * @throws UndecryptableConversationContent
     */
    public static function revealStrict(?string $value): ?string
    {
        if ($value === null || $value === '' || ! static::looksEncrypted($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable $e) {
            throw new UndecryptableConversationContent('A conversation column holds ciphertext this app key cannot decrypt.', previous: $e);
        }
    }

    /**
     * {@see revealStrict()} then decode, as revealJson() does.
     *
     * @return array<array-key, mixed>
     *
     * @throws UndecryptableConversationContent
     */
    public static function revealJsonStrict(?string $value): array
    {
        return is_array($decoded = json_decode(static::revealStrict($value) ?? '', true)) ? $decoded : [];
    }

    /**
     * Whether a stored value is shaped like a Laravel encrypted payload
     * (base64 JSON carrying iv / value / mac).
     */
    public static function looksEncrypted(?string $value): bool
    {
        if ($value === null || $value === '' || ($decoded = base64_decode($value, true)) === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /**
     * Reveal a stored JSON column and decode it, tolerating null, plaintext
     * and malformed values (all of which decode to an empty array).
     *
     * @return array<array-key, mixed>
     */
    public static function revealJson(?string $value): array
    {
        return is_array($decoded = json_decode(static::reveal($value) ?? '', true)) ? $decoded : [];
    }

    /**
     * Encrypt message text for storage, leaving blank text blank.
     *
     * An empty string encrypts to a non-empty ciphertext, which would flip
     * the store's filled()/blank() checks on stored rows.
     */
    public static function conceal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Encrypt a serialized JSON column, leaving empty markers plaintext so
     * SQL emptiness predicates (`meta != '[]'`) keep meaning what they say.
     */
    public static function concealJson(?string $value): ?string
    {
        if ($value === null || in_array($value, ['', '[]', '{}', 'null'], true)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Whether the bound conversation store seals rows at rest — what an
     * out-of-band writer (a migration, a console command) checks so it
     * writes a row the way the store itself would.
     */
    public static function encryptsAtRest(): bool
    {
        return app(ConversationStore::class) instanceof EncryptedConversationStore;
    }
}

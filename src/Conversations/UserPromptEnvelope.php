<?php

namespace Saad\AiKit\Conversations;

/**
 * The ONE prompt envelope for user turns that carry app context — and the ONE
 * rule for showing such a stored turn back as just the user's own words.
 *
 * Apps wrap per-turn context around the user's message before handing it to
 * the agent: an attachment inventory, extraction evidence, surface hints. The
 * SDK stores whatever was streamed, so that whole envelope becomes the stored
 * user message — and every reader downstream (the chat panel rehydrating a
 * thread, an admin ledger, an export) then shows the user a wall of machine
 * context above the two lines they actually typed. Catodemy shipped exactly
 * that bug; the next app would have shipped it again, each with its own strip
 * regex. Hence one kit seam (ruling #25's companion: attachments are durable
 * history, but CONTEXT is not the user's words).
 *
 * The contract has two halves that must never drift apart, which is why they
 * live in one class:
 *
 *  - {@see wrap()} builds the envelope: a kit-owned HEADER line, the app's
 *    context blocks, then the SENTINEL and the user's message — always last,
 *    where the model expects it.
 *  - {@see displayText()} inverts it: content that starts with a recognized
 *    header is cut to what follows the LAST sentinel; anything else passes
 *    through untouched.
 *
 * The header is what makes the strip safe. The sentinel alone is ordinary
 * prose a user could legitimately type; stripping on it alone would mangle
 * their message. Only content that BEGINS with a header the envelope (or a
 * caller-named legacy wrapper) actually wrote is ever cut. Apps with envelopes
 * that predate this seam pass those headers via $legacyHeaders so their old
 * threads clean up through the same call.
 */
class UserPromptEnvelope
{
    /**
     * The first line of every kit-authored envelope. Addressed to the model
     * ("not written by the user") so it needs no other framing, and specific
     * enough that organic user text starting with it is vanishingly unlikely.
     */
    public const string HEADER = 'Context for this turn (not written by the user):';

    /** The line separating the app's context from the user's own words. */
    public const string SENTINEL = "User message:\n";

    /**
     * Wrap the user's message with the app's context blocks. No blocks — no
     * envelope: the bare prompt keeps its provider-cache-friendly shape, and
     * displayText() correctly leaves it untouched.
     *
     * @param  list<string>  $contextBlocks  already-rendered blocks (an
     *                                       attachment inventory, extraction text, …); empty/blank
     *                                       blocks are dropped
     */
    public static function wrap(array $contextBlocks, string $prompt): string
    {
        $blocks = array_values(array_filter($contextBlocks, fn (string $block): bool => trim($block) !== ''));

        if ($blocks === []) {
            return $prompt;
        }

        return self::HEADER."\n\n"
            .implode("\n\n", $blocks)
            ."\n\n".self::SENTINEL.$prompt;
    }

    /**
     * The user's own words out of a stored turn — the display seam every
     * reader of user-role rows shares (pair with
     * {@see ConversationContent::reveal()}, which owns the decrypt half).
     *
     * @param  list<string>  $legacyHeaders  additional envelope openers this
     *                                       app shipped BEFORE adopting the kit envelope; content starting
     *                                       with one of them strips by the same sentinel rule
     */
    public static function displayText(string $stored, array $legacyHeaders = []): string
    {
        $recognized = false;

        foreach ([self::HEADER, ...$legacyHeaders] as $header) {
            if ($header !== '' && str_starts_with($stored, $header)) {
                $recognized = true;

                break;
            }
        }

        if (! $recognized) {
            return $stored;
        }

        // The LAST sentinel: context blocks are app-rendered text and may
        // themselves quote the sentinel (an inventory listing a file named
        // "User message:.txt" is far-fetched but free to be robust against);
        // the envelope always writes the real one last.
        $at = strrpos($stored, self::SENTINEL);

        if ($at === false) {
            // A recognized header with no sentinel is a malformed envelope —
            // pass it through rather than guess at a cut point.
            return $stored;
        }

        return substr($stored, $at + strlen(self::SENTINEL));
    }
}

<?php

use Saad\AiKit\Conversations\UserPromptEnvelope;

/**
 * The prompt envelope + display strip, as one round-trip contract: whatever
 * wrap() authors, displayText() must reduce back to the user's own words —
 * and NOTHING else may ever be cut.
 */
it('wraps context blocks under the kit header with the message last', function (): void {
    $wrapped = UserPromptEnvelope::wrap(
        ["Files attached:\n[attachment:abc] deck.pptx", 'Second block'],
        'ترجم هذا الملف',
    );

    expect($wrapped)->toBe(
        UserPromptEnvelope::HEADER."\n\n"
        ."Files attached:\n[attachment:abc] deck.pptx\n\n"
        ."Second block\n\n"
        .UserPromptEnvelope::SENTINEL.'ترجم هذا الملف',
    );
});

it('returns the bare prompt when there are no context blocks (cache-friendly)', function (): void {
    expect(UserPromptEnvelope::wrap([], 'hello'))->toBe('hello')
        // Blank blocks are not an envelope either.
        ->and(UserPromptEnvelope::wrap(['', "  \n"], 'hello'))->toBe('hello');
});

it('round-trips: displayText recovers exactly the wrapped message', function (): void {
    $prompt = "Now translate it.\nAnd bank it.";

    $stored = UserPromptEnvelope::wrap(['[attachment:x] a.pdf'], $prompt);

    expect(UserPromptEnvelope::displayText($stored))->toBe($prompt);
});

it('cuts at the LAST sentinel when a context block quotes it', function (): void {
    $stored = UserPromptEnvelope::wrap(
        ["A block that quotes the sentinel:\n".UserPromptEnvelope::SENTINEL.'not the real one'],
        'the real message',
    );

    expect(UserPromptEnvelope::displayText($stored))->toBe('the real message');
});

it('never strips a bare message — even one that contains the sentinel as prose', function (): void {
    $organic = "I typed\nUser message:\nby hand, honestly.";

    expect(UserPromptEnvelope::displayText($organic))->toBe($organic);
});

it('strips a legacy envelope by its caller-named header, same sentinel rule', function (): void {
    $legacyHeader = 'The user attached one or more files; extractors have already read them';
    $stored = $legacyHeader." — use the extraction(s) below as read-only evidence.\n\n"
        ."Attached document: deck.pdf\n60k chars of extraction…\n\n"
        .UserPromptEnvelope::SENTINEL.'ترجم لي هذا الملف';

    expect(UserPromptEnvelope::displayText($stored, [$legacyHeader]))->toBe('ترجم لي هذا الملف')
        // The same content WITHOUT naming the legacy header is untouched —
        // the header allowlist is what makes stripping safe to apply blindly.
        ->and(UserPromptEnvelope::displayText($stored))->toBe($stored);
});

it('passes a malformed envelope (header, no sentinel) through rather than guessing', function (): void {
    $malformed = UserPromptEnvelope::HEADER."\n\njust blocks, no message marker";

    expect(UserPromptEnvelope::displayText($malformed))->toBe($malformed);
});

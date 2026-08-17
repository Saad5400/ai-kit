<?php

use Illuminate\Support\Facades\Crypt;
use Saad\AiKit\Conversations\ConversationContent;

it('reveals ciphertext written by the encrypted store', function () {
    expect(ConversationContent::reveal(Crypt::encryptString('كم معدلي؟')))->toBe('كم معدلي؟');
});

it('passes pre-encryption plaintext through untouched', function () {
    expect(ConversationContent::reveal('plain old row'))->toBe('plain old row')
        ->and(ConversationContent::reveal(''))->toBe('')
        ->and(ConversationContent::reveal(null))->toBeNull();
});

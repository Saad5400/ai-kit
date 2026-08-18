<?php

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\EncryptedConversationStore;

it('binds the encrypted store over the vendor contract by default', function () {
    expect(app(ConversationStore::class))
        ->toBeInstanceOf(EncryptedConversationStore::class)
        ->toBe(app(ConversationStore::class)); // singleton
});

it('stays rebindable so apps can supply their own store', function () {
    $custom = new class extends DatabaseConversationStore {};

    app()->singleton(ConversationStore::class, fn () => $custom);

    expect(app(ConversationStore::class))->toBe($custom);
});

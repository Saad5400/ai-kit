<?php

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\EncryptedConversationStore;

it('leaves the vendor store bound by default, since encryption is opt-in', function () {
    expect(app(ConversationStore::class))
        ->toBeInstanceOf(DatabaseConversationStore::class)
        ->not->toBeInstanceOf(EncryptedConversationStore::class);
});

it('stays rebindable so apps can supply their own store', function () {
    $custom = new class extends DatabaseConversationStore {};

    app()->singleton(ConversationStore::class, fn () => $custom);

    expect(app(ConversationStore::class))->toBe($custom);
});

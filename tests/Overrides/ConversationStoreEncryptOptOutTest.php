<?php

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Tests\ConversationsEncryptDisabledTestCase;

uses(ConversationsEncryptDisabledTestCase::class);

it('leaves the vendor store bound when the app opts out of encryption', function () {
    expect(app(ConversationStore::class))
        ->toBeInstanceOf(DatabaseConversationStore::class)
        ->not->toBeInstanceOf(EncryptedConversationStore::class);
});

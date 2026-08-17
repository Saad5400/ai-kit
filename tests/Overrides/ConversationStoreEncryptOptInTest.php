<?php

use Laravel\Ai\Contracts\ConversationStore;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Tests\ConversationsEncryptEnabledTestCase;

uses(ConversationsEncryptEnabledTestCase::class);

it('binds the encrypted store over the vendor contract when conversations.encrypt is opted into', function () {
    expect(app(ConversationStore::class))
        ->toBeInstanceOf(EncryptedConversationStore::class)
        ->toBe(app(ConversationStore::class)); // singleton
});

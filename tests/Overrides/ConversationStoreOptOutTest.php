<?php

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Saad\AiKit\Conversations\EncryptedConversationStore;
use Saad\AiKit\Tests\ConversationsEncryptDisabledTestCase;

uses(ConversationsEncryptDisabledTestCase::class);

it('leaves the vendor store bound when conversations.encrypt is off', function () {
    $store = app(ConversationStore::class);

    expect($store)->toBeInstanceOf(DatabaseConversationStore::class)
        ->not->toBeInstanceOf(EncryptedConversationStore::class);
});

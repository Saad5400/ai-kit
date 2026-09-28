<?php

namespace Saad\AiKit\Conversations;

use RuntimeException;

/**
 * A conversation column holds a Laravel encrypted payload the current app
 * key (and `APP_PREVIOUS_KEYS`) cannot decrypt. Raised by
 * {@see ConversationContent::revealStrict()} so a writer leaves the row alone
 * instead of persisting the ciphertext as text or overwriting what it could
 * not read.
 */
class UndecryptableConversationContent extends RuntimeException {}

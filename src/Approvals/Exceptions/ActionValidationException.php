<?php

namespace Saad\AiKit\Approvals\Exceptions;

use RuntimeException;
use Saad\AiKit\Approvals\Contracts\ProposableAction;

/**
 * An expected, user-facing failure while validating or executing a
 * {@see ProposableAction}: stale ids, a type
 * mismatch, state that changed since propose time. The message is surfaced on
 * the action card (`error`), so keep it human-readable.
 */
class ActionValidationException extends RuntimeException {}

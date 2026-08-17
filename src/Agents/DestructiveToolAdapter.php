<?php

namespace Saad\AiKit\Agents;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Behaviourally identical to {@see WriteToolAdapter}; exists only to carry
 * the #[IsDestructive] annotation — PHP attributes are not inherited, so
 * the flag cannot be flipped on the parent. Pick this adapter for the
 * dedicated hard-delete ("Delete...") tools so external clients render
 * their strongest confirmation UI.
 */
#[IsDestructive]
class DestructiveToolAdapter extends WriteToolAdapter {}

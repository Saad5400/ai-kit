<?php

namespace Saad\AiKit\Tests\Support;

/**
 * A container-resolvable action registered by CLASS NAME, so registry tests
 * can tell a deferred registration apart from an instance one for the same
 * action type.
 */
class DeferredProposableAction extends FakeProposableAction
{
    public function __construct()
    {
        parent::__construct(type: 'update_widget', category: 'deferred');
    }
}

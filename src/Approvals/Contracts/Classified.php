<?php

namespace Saad\AiKit\Approvals\Contracts;

use Saad\AiKit\Approvals\Classified\Capability;

/**
 * A tool that declares what it does to the world. The capability is code,
 * not prompt text — the model can neither see nor influence it, so the
 * `destructive` flag the client renders is always the server's own word.
 */
interface Classified
{
    public function capability(): Capability;
}

<?php

namespace Saad\AiKit\Safety\Events;

class KillSwitchEngaged
{
    public function __construct(
        public readonly ?string $scope,
        public readonly ?string $reason,
    ) {}
}

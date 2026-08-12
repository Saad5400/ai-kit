<?php

namespace Saad\AiKit\Safety\Events;

class KillSwitchReleased
{
    public function __construct(
        public readonly ?string $scope,
    ) {}
}

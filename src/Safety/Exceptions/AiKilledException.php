<?php

namespace Saad\AiKit\Safety\Exceptions;

class AiKilledException extends AiUnavailableException
{
    public function __construct(
        public readonly ?string $scope = null,
        public readonly ?string $adminReason = null,
    ) {
        parent::__construct(sprintf(
            'AI kill switch engaged%s%s.',
            $scope !== null ? " for scope [{$scope}]" : ' globally',
            $adminReason !== null ? ": {$adminReason}" : '',
        ));
    }

    public function userFacingReason(): string
    {
        return __('ai-kit::safety.killed');
    }
}

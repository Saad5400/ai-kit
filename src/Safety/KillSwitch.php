<?php

namespace Saad\AiKit\Safety;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Saad\AiKit\Safety\Events\KillSwitchEngaged;
use Saad\AiKit\Safety\Events\KillSwitchReleased;
use Saad\AiKit\Safety\Exceptions\AiKilledException;

class KillSwitch
{
    public function __construct(
        protected Repository $cache,
        protected Dispatcher $events,
        protected ?SafetySettings $settings = null,
    ) {}

    /**
     * Engage the switch. A null scope disables AI globally; a named scope
     * (e.g. "chat", "admin", a model id) disables only that surface.
     */
    public function engage(?string $scope = null, ?string $reason = null): void
    {
        $this->cache->forever($this->key($scope), [
            'reason' => $reason,
            'engaged_at' => now()->toIso8601String(),
        ]);

        $this->events->dispatch(new KillSwitchEngaged($scope, $reason));
    }

    public function release(?string $scope = null): void
    {
        $this->cache->forget($this->key($scope));

        $this->events->dispatch(new KillSwitchReleased($scope));
    }

    /**
     * A scope is considered engaged when the global switch is on, the
     * scope's own switch is on, or the app's settings store disables the
     * surface (the scope doubles as the settings feature name).
     */
    public function engaged(?string $scope = null): bool
    {
        if ($this->settings !== null && ! $this->settings->enabled($scope)) {
            return true;
        }

        if ($this->cache->has($this->key(null))) {
            return true;
        }

        return $scope !== null && $this->cache->has($this->key($scope));
    }

    public function reason(?string $scope = null): ?string
    {
        $value = $this->cache->get($this->key(null));

        if ($value === null && $scope !== null) {
            $value = $this->cache->get($this->key($scope));
        }

        return $value['reason'] ?? null;
    }

    /**
     * @throws AiKilledException
     */
    public function enforce(?string $scope = null): void
    {
        if ($this->engaged($scope)) {
            throw new AiKilledException($scope, $this->reason($scope));
        }
    }

    protected function key(?string $scope): string
    {
        return 'ai-kit:kill:'.($scope ?? 'global');
    }
}

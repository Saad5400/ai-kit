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

    /**
     * Clear the scope's cache switch. Returns whether the scope is now
     * actually live — false when it stays engaged through another switch
     * (the global one, or the app's settings store), in which case no
     * KillSwitchReleased is dispatched: dashboards must not announce a
     * recovery that did not happen.
     */
    public function release(?string $scope = null): bool
    {
        $this->cache->forget($this->key($scope));

        if ($this->engaged($scope)) {
            return false;
        }

        $this->events->dispatch(new KillSwitchReleased($scope));

        return true;
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

    /**
     * Why the scope is off: the engaging cache entry's reason, or — when
     * the engagement comes from the settings store instead — a translated
     * generic reason, so a settings-disabled surface never reads as
     * "engaged with no explanation".
     */
    public function reason(?string $scope = null): ?string
    {
        $value = $this->cache->get($this->key(null));

        if ($value === null && $scope !== null) {
            $value = $this->cache->get($this->key($scope));
        }

        $reason = $value['reason'] ?? null;

        if ($reason === null && $this->settings !== null && ! $this->settings->enabled($scope)) {
            return __('ai-kit::safety.disabled_by_settings');
        }

        return $reason;
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

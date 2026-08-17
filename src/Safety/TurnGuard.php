<?php

namespace Saad\AiKit\Safety;

use Closure;
use Saad\AiKit\Safety\Exceptions\AiUnavailableException;

/**
 * The single turn-entry gate, composing the kill switch, the daily budget
 * and (when an owner is given) the per-owner concurrency cap. Callers
 * catch AiUnavailableException and render its userFacingReason() as a
 * normal assistant reply — degraded mode must never surface as a raw 500.
 */
class TurnGuard
{
    public function __construct(
        protected KillSwitch $killSwitch,
        protected BudgetGuard $budget,
        protected TurnConcurrencyLimiter $concurrency,
    ) {}

    /**
     * Pre-flight check: kill switch, budget, then — only when an owner is
     * given — the concurrency cap. Does not reserve a concurrency slot;
     * use run() to actually hold one for the turn's duration.
     *
     * @throws AiUnavailableException
     */
    public function check(?string $scope = null, ?string $owner = null): void
    {
        $this->killSwitch->enforce($scope);
        $this->budget->enforce();

        if ($owner !== null) {
            $this->concurrency->enforce($owner);
        }
    }

    /**
     * Non-throwing form of check(), for availability probes (greying out
     * a chat box, hiding an entry point).
     */
    public function allowed(?string $scope = null, ?string $owner = null): bool
    {
        try {
            $this->check($scope, $owner);
        } catch (AiUnavailableException) {
            return false;
        }

        return true;
    }

    /**
     * Guard and run a turn. When an owner is given, a concurrency slot is
     * held for the callback's duration and released even on failure. The
     * kill switch and budget are enforced before any slot is taken.
     *
     * @throws AiUnavailableException
     */
    public function run(Closure $callback, ?string $scope = null, ?string $owner = null): mixed
    {
        $this->killSwitch->enforce($scope);
        $this->budget->enforce();

        return $owner !== null
            ? $this->concurrency->run($owner, $callback)
            : $callback();
    }
}

<?php

namespace Saad\AiKit\Safety;

/**
 * The app-facing source of truth for AI availability. The kit binds a
 * config-backed default; apps with an operator-editable settings store
 * (e.g. Spatie settings) rebind this contract so the kill switch and
 * budget guard follow live settings instead of deploy-time config.
 *
 * Semantics: enabled(null) is the master switch; enabled('feature') must
 * honor the master switch first, then the feature's own toggle. A null
 * dailyBudgetUsd() means unlimited; a value <= 0 means the budget is
 * exhausted outright, doubling as a kill switch for budget-gated surfaces.
 */
interface SafetySettings
{
    public function enabled(?string $feature = null): bool;

    public function dailyBudgetUsd(): ?float;
}

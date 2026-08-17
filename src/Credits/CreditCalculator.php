<?php

namespace Saad\AiKit\Credits;

/**
 * The cost → credits math shared by catodemy and s-grade, config-driven so
 * each app keeps its own margin and unit. Margin is applied AT CONSUMPTION,
 * not at sale — that is what guarantees the margin on every credit spent
 * independent of the credit unit — and `ceil()` is the never-below-cost
 * guarantee: it over-charges by less than one credit and never erodes
 * margin.
 */
class CreditCalculator
{
    public function margin(): float
    {
        return (float) config('ai-kit.credits.margin', 0.10);
    }

    public function creditUnitUsd(): float
    {
        return (float) config('ai-kit.credits.credit_unit_usd', 0.0004);
    }

    public function usdToSar(): float
    {
        return (float) config('ai-kit.credits.usd_to_sar', 3.75);
    }

    public function creditsForCostUsd(float $rawCostUsd, ?float $margin = null): int
    {
        if ($rawCostUsd <= 0) {
            return 0;
        }

        $retailUsd = $rawCostUsd * (1 + ($margin ?? $this->margin()));

        return (int) ceil($retailUsd / $this->creditUnitUsd());
    }

    /**
     * The SAR price floor below which a package of this size would sell
     * credits under their retail cost.
     */
    public function packageFloorSar(int $credits): float
    {
        return $credits * $this->creditUnitUsd() * $this->usdToSar();
    }

    /**
     * Display-only "≈ N messages" figure for balance indicators.
     */
    public function messagesFor(int $credits): int
    {
        $perMessage = max(1, (int) config('ai-kit.credits.credits_per_message_estimate', 10));

        return intdiv(max(0, $credits), $perMessage);
    }
}

<?php

namespace Saad\AiKit\Credits;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The turn-metering policy both apps independently converged on, extracted:
 * resolve the turn's true cost, decide the waivers, convert to credits, and
 * debit exactly once under `debit:turn:{id}`. Wallet selection stays behind
 * the app's {@see CreditDebitor}.
 *
 * Waivers, in order:
 *  - no resolvable cost → nothing to bill (never invent a charge);
 *  - `planOnly` → a planning turn that produced a plan is always waived:
 *    the execute turn bills the real work, so a dismissed plan costs the
 *    user nothing and an approved plan's cheap planning cost is margin
 *    (deliberately separate from the chit-chat waiver — a planning turn DID
 *    call a tool and would not hit that gate);
 *  - free chit-chat → no tools, no attachments, and resolved cost at or
 *    under `free_turn_max_cost_usd` (a ceiling of 0 disables the waiver).
 *    Gating on resolved USD — never a completion-token proxy — keeps a big
 *    prompt, a substantive answer, or attachment-extraction cost in scope;
 *  - zero credits after conversion.
 *
 * `usedTools` must be a turn-wide latch at the caller: a tool-using attempt
 * followed by an empty-reply retry that answers tool-lessly must still
 * count as having used tools.
 */
class CreditMeter
{
    public function __construct(
        protected CreditCalculator $calculator,
        protected CreditDebitor $debitor,
    ) {}

    /**
     * @param  mixed  $payer  passed through to the app's CreditDebitor untouched
     * @param  float|null  $providerCostUsd  the provider-reported exact cost, preferred
     * @param  float|null  $estimatedCostUsd  fallback estimate from declared prices
     * @param  array<string, mixed>  $meta  merged into the debit's meta
     */
    public function chargeTurn(
        mixed $payer,
        string $turnId,
        ?float $providerCostUsd,
        ?float $estimatedCostUsd = null,
        ?string $costSource = null,
        bool $usedTools = true,
        bool $hasAttachments = false,
        bool $planOnly = false,
        array $meta = [],
    ): ChargeResult {
        // Prefer the provider-reported cost; a present-but-null source
        // defaults rather than dropping the charge over a missing string.
        [$cost, $source] = $providerCostUsd !== null && $providerCostUsd > 0
            ? [$providerCostUsd, $costSource ?? 'provider_usage']
            : [$estimatedCostUsd, 'estimated'];

        if ($cost === null || $cost <= 0) {
            return ChargeResult::waived('no_cost');
        }

        if ($planOnly) {
            return ChargeResult::waived('plan_only', $cost, $source);
        }

        $freeTurnCeiling = (float) config('ai-kit.credits.free_turn_max_cost_usd', 0.0006);

        if (! $usedTools && ! $hasAttachments && $freeTurnCeiling > 0 && $cost <= $freeTurnCeiling) {
            return ChargeResult::waived('free_turn', $cost, $source);
        }

        $credits = $this->calculator->creditsForCostUsd($cost);

        if ($credits === 0) {
            return ChargeResult::waived('zero_credits', $cost, $source);
        }

        try {
            $outcome = $this->debitor->debit(
                $payer,
                $credits,
                $meta + ['turn_id' => $turnId, 'cost_usd' => $cost, 'cost_source' => $source],
                'debit:turn:'.$turnId,
            );
        } catch (UniqueConstraintViolationException) {
            // Already debited for this turn by a concurrent worker — no-op.
            return ChargeResult::alreadyCharged($credits, $cost, $source);
        }

        return ChargeResult::charged(
            $outcome['debited'] ?? $credits,
            $outcome['write_off'] ?? 0,
            $cost,
            $source,
        );
    }
}

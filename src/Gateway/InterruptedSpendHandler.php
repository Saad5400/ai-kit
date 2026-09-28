<?php

namespace Saad\AiKit\Gateway;

/**
 * The app's side of an interrupted turn's spend: bind it to debit (or just
 * log) what a stopped or failed turn actually cost once the kit has priced
 * it. The kit binds {@see NullInterruptedSpendHandler} by default.
 *
 * Called AT MOST ONCE per settled turn (per turn id + generation set), and
 * only with a cost above zero — from {@see InterruptedSpend::dispatchFor()}
 * when everything was priced on the spot, otherwise from the queued
 * {@see ResolveInterruptedSpend} (on the last try with whatever did price;
 * generations OpenRouter never priced are left out). The daily budget has
 * already been recorded by then, whatever the handler does.
 *
 * `$costUsd` is everything the turn spent that NO usage row recorded — the
 * kit writes no usage row for a stopped or failed turn — i.e. the steps it
 * completed before the stop/failure plus the step that was cut off. Spend
 * already metered through usage rows (a vision pre-pass, a helper call) is
 * not in it.
 *
 * Implementations must be idempotent on their own side too (a queue can
 * redeliver): debit under a key such as `debit:turn:{turnId}:interrupted`.
 * `$context` is whatever the app passed to dispatchFor — tell a stopped turn
 * (debit) from a failed one (record only) there.
 */
interface InterruptedSpendHandler
{
    /**
     * @param  list<string>  $generationIds  the generations the cost covers
     * @param  array<string, mixed>  $context
     */
    public function resolved(string $turnId, float $costUsd, array $generationIds, array $context): void;
}

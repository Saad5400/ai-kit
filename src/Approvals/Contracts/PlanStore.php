<?php

namespace Saad\AiKit\Approvals\Contracts;

use Saad\AiKit\Approvals\Plan;

/**
 * Persists a proposed {@see Plan} between the propose turn (which builds it)
 * and the confirm turn (which re-POSTs its id to execute it). Plans are
 * owner-scoped: a foreign or unknown id yields null, so one owner can never
 * execute another's plan. Plans expire — an abandoned plan quietly lapses.
 *
 * The owner is a string so it works for user ids ("42") and owner keys
 * ("telegram:123") alike.
 */
interface PlanStore
{
    public function put(Plan $plan, string $owner): void;

    public function get(string $planId, string $owner): ?Plan;

    public function forget(string $planId): void;
}

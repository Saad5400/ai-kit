<?php

namespace Saad\AiKit\Credits;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The wallet-policy seam: the kit's {@see CreditMeter} decides WHETHER and
 * HOW MUCH to charge; the app decides WHICH wallets pay and in what order
 * (catodemy spills across a resolved multi-wallet plan; s-grade spends an
 * included-then-purchased two-bucket balance). Implementations must:
 *
 *  - treat `$idempotencyKey` as unique-once: a duplicate insert must raise
 *    {@see UniqueConstraintViolationException} (the
 *    meter converts it into an already-charged no-op);
 *  - clamp at zero and report the unpaid remainder as `write_off`, never
 *    charge a negative balance;
 *  - consume the key even on a pure write-off (record a zero-amount row),
 *    so a retry cannot double-count a shortfall.
 */
interface CreditDebitor
{
    /**
     * @param  mixed  $payer  the app's payer handle (a user, a resolved
     *                        spend plan, an owner key — the meter passes it
     *                        through untouched)
     * @param  array<string, mixed>  $meta
     * @return array{requested: int, debited: int, write_off: int}
     */
    public function debit(mixed $payer, int $credits, array $meta = [], ?string $idempotencyKey = null): array;
}

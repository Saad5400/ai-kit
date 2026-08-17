<?php

namespace Saad\AiKit\Credits;

/**
 * The outcome of one metering decision. `already_charged` means the debit's
 * idempotency key was already consumed (a concurrent worker or a retried
 * job) — a no-op for the caller, reported distinctly so nothing re-charges.
 */
final class ChargeResult
{
    public const STATUS_CHARGED = 'charged';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_ALREADY_CHARGED = 'already_charged';

    private function __construct(
        public readonly string $status,
        public readonly int $creditsCharged = 0,
        public readonly int $writeOff = 0,
        public readonly ?float $costUsd = null,
        public readonly ?string $costSource = null,
        public readonly ?string $waiveReason = null,
    ) {}

    public static function charged(int $credits, int $writeOff, float $costUsd, string $costSource): self
    {
        return new self(self::STATUS_CHARGED, $credits, $writeOff, $costUsd, $costSource);
    }

    public static function waived(string $reason, ?float $costUsd = null, ?string $costSource = null): self
    {
        return new self(self::STATUS_WAIVED, 0, 0, $costUsd, $costSource, $reason);
    }

    public static function alreadyCharged(int $credits, ?float $costUsd = null, ?string $costSource = null): self
    {
        return new self(self::STATUS_ALREADY_CHARGED, $credits, 0, $costUsd, $costSource);
    }

    public function isCharged(): bool
    {
        return $this->status === self::STATUS_CHARGED;
    }

    public function isWaived(): bool
    {
        return $this->status === self::STATUS_WAIVED;
    }
}

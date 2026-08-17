<?php

namespace Saad\AiKit\Testing;

use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Assert;
use Saad\AiKit\Credits\CreditDebitor;

/**
 * In-memory {@see CreditDebitor} honouring the contract's semantics: a
 * reused idempotency key raises the same UniqueConstraintViolationException
 * a real unique column would, and a bounded balance clamps at zero with the
 * shortfall reported as write_off.
 */
class FakeCreditDebitor implements CreditDebitor
{
    /** @var list<array{payer: mixed, credits: int, meta: array<string, mixed>, key: string|null}> */
    public array $debits = [];

    /** @var array<string, true> */
    protected array $usedKeys = [];

    public function __construct(protected ?int $balance = null) {}

    public function debit(mixed $payer, int $credits, array $meta = [], ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            if (isset($this->usedKeys[$idempotencyKey])) {
                throw new UniqueConstraintViolationException(
                    'testing',
                    'insert into credit_transactions',
                    [],
                    new \RuntimeException("Duplicate idempotency key [{$idempotencyKey}]."),
                );
            }

            $this->usedKeys[$idempotencyKey] = true;
        }

        $debited = $this->balance === null ? $credits : min($credits, max(0, $this->balance));

        if ($this->balance !== null) {
            $this->balance -= $debited;
        }

        $this->debits[] = ['payer' => $payer, 'credits' => $credits, 'meta' => $meta, 'key' => $idempotencyKey];

        return ['requested' => $credits, 'debited' => $debited, 'write_off' => $credits - $debited];
    }

    public function assertDebited(int $credits, ?string $key = null): void
    {
        Assert::assertTrue(
            collect($this->debits)->contains(
                fn (array $debit): bool => $debit['credits'] === $credits
                    && ($key === null || $debit['key'] === $key),
            ),
            sprintf('Expected a debit of %d credits%s, none recorded.', $credits, $key !== null ? " under [{$key}]" : ''),
        );
    }

    public function assertNothingDebited(): void
    {
        Assert::assertSame([], $this->debits, 'Expected no debits, but some were recorded.');
    }
}

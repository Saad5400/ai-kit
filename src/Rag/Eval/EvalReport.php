<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * The scored result of one eval run: per-slice means, per-query scores, the
 * negatives' false-positive rate and wall-time percentiles. Serialises to
 * the JSON a baseline file holds, and back.
 *
 * Slices are named `all`, `intent:<x>`, `caller:<x>`, `label:<x>`,
 * `course:<x>` and `phen:<x>`; each is `{n, recall, hit1, mrr, ndcg}`.
 */
final class EvalReport
{
    /**
     * @param  array<string, array{n: int, recall: float, hit1: float, mrr: float, ndcg: float}>  $slices
     * @param  array<string, array{recall: float, hit1: float, mrr: float, ndcg: float}>  $perQuery
     * @param  array{n: int, p50: float|null, p95: float|null}  $latency
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $k,
        public readonly int $nScored,
        public readonly ?float $falsePositiveRate,
        public readonly int $negatives,
        public readonly array $slices,
        public readonly array $perQuery,
        public readonly array $latency = ['n' => 0, 'p50' => null, 'p95' => null],
        public readonly ?string $fingerprint = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{n: int, recall: float, hit1: float, mrr: float, ndcg: float}|null
     */
    public function slice(string $name): ?array
    {
        return $this->slices[$name] ?? null;
    }

    /**
     * Nearest-rank p50/p95 of the given wall times (ms).
     *
     * @param  list<float>  $values
     * @return array{n: int, p50: float|null, p95: float|null}
     */
    public static function percentiles(array $values): array
    {
        if ($values === []) {
            return ['n' => 0, 'p50' => null, 'p95' => null];
        }

        sort($values);
        $at = static fn (float $p): float => $values[max(0, (int) ceil($p * count($values)) - 1)];

        return ['n' => count($values), 'p50' => $at(0.50), 'p95' => $at(0.95)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'k' => $this->k,
            'n_scored' => $this->nScored,
            'false_positive_rate_on_negatives' => $this->falsePositiveRate,
            'negatives' => $this->negatives,
            'latency_ms' => $this->latency,
            'fingerprint' => $this->fingerprint,
            'meta' => $this->meta,
            'slices' => $this->slices,
            'per_query' => $this->perQuery,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            k: (int) ($data['k'] ?? 10),
            nScored: (int) ($data['n_scored'] ?? 0),
            falsePositiveRate: isset($data['false_positive_rate_on_negatives']) ? (float) $data['false_positive_rate_on_negatives'] : null,
            negatives: (int) ($data['negatives'] ?? 0),
            slices: (array) ($data['slices'] ?? []),
            perQuery: (array) ($data['per_query'] ?? []),
            latency: (array) ($data['latency_ms'] ?? ['n' => 0, 'p50' => null, 'p95' => null]),
            fingerprint: isset($data['fingerprint']) ? (string) $data['fingerprint'] : null,
            meta: (array) ($data['meta'] ?? []),
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** The human table: one row per slice, then the negatives and latency. */
    public function table(): string
    {
        $lines = [sprintf('%-32s %3s %6s %6s %6s %6s', 'slice', 'n', 'R@'.$this->k, 'Hit@1', 'MRR', 'nDCG')];

        foreach ($this->slices as $name => $slice) {
            $lines[] = sprintf('%-32s %3d %6.3f %6.3f %6.3f %6.3f', $name, $slice['n'], $slice['recall'], $slice['hit1'], $slice['mrr'], $slice['ndcg']);
        }

        $lines[] = 'negatives false-positive rate: '.($this->falsePositiveRate === null ? 'n/a' : sprintf('%.3f (n=%d)', $this->falsePositiveRate, $this->negatives));

        if ($this->latency['n'] > 0) {
            $lines[] = sprintf('latency p50 %.0f ms · p95 %.0f ms (n=%d)', $this->latency['p50'], $this->latency['p95'], $this->latency['n']);
        }

        return implode("\n", $lines);
    }
}

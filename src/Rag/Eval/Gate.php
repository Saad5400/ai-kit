<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * The "don't regress" merge gate: compares a candidate run with the baseline
 * measured on the same data. It fails when
 *
 *  - overall Recall@k or nDCG@k drops by more than `overallDrop` (0.01);
 *  - any slice named `slicePrefix…` (intent slices) with at least
 *    `sliceMinN` items in the baseline drops on either metric by more than
 *    `sliceDrop` (0.05);
 *  - the negatives' false-positive rate rises at all;
 *
 * and REFUSES (neither pass nor fail) when both reports carry fingerprints
 * that differ — a baseline from another snapshot proves nothing.
 */
final class Gate
{
    public function __construct(
        public readonly float $overallDrop = 0.01,
        public readonly float $sliceDrop = 0.05,
        public readonly int $sliceMinN = 5,
        public readonly string $slicePrefix = 'intent:',
    ) {}

    public function check(EvalReport $baseline, EvalReport $candidate): GateResult
    {
        if ($baseline->fingerprint !== null && $candidate->fingerprint !== null && $baseline->fingerprint !== $candidate->fingerprint) {
            return new GateResult(refused: "fingerprint mismatch: baseline {$baseline->fingerprint}, run {$candidate->fingerprint} — re-baseline on main first");
        }

        $failures = [];

        foreach (['recall', 'ndcg'] as $metric) {
            $failure = $this->drop('all', $metric, $baseline, $candidate, $this->overallDrop);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        foreach ($baseline->slices as $name => $slice) {
            if (! str_starts_with($name, $this->slicePrefix) || $slice['n'] < $this->sliceMinN) {
                continue;
            }

            foreach (['recall', 'ndcg'] as $metric) {
                $failure = $this->drop($name, $metric, $baseline, $candidate, $this->sliceDrop);

                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
        }

        if ($baseline->falsePositiveRate !== null && $candidate->falsePositiveRate !== null
            && $candidate->falsePositiveRate > $baseline->falsePositiveRate + 1e-9) {
            $failures[] = sprintf('negatives false-positive rate rose %.3f → %.3f', $baseline->falsePositiveRate, $candidate->falsePositiveRate);
        }

        return new GateResult($failures);
    }

    private function drop(string $slice, string $metric, EvalReport $baseline, EvalReport $candidate, float $tolerance): ?string
    {
        $before = $baseline->slice($slice)[$metric] ?? null;
        $after = $candidate->slice($slice)[$metric] ?? null;

        if ($before === null) {
            return null;
        }

        if ($after === null) {
            return "{$slice} {$metric}: missing from the run";
        }

        return $before - $after > $tolerance + 1e-12
            ? sprintf('%s %s dropped %.4f → %.4f (more than %.2f)', $slice, $metric, $before, $after, $tolerance)
            : null;
    }
}

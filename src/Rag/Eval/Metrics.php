<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * Retrieval metrics over a golden set — a port of catodemy's search-review
 * `eval_metrics.py`, held to it by a parity test on a shared fixture.
 *
 * Per query, over the top `k` hits: Recall@k (share of targets credited),
 * Hit@1, MRR@k and nDCG@k (gain 2^grade − 1, log2(rank + 1) discount).
 *
 * Crediting (each target credited once, by its best-ranked match):
 *  - a `moment` target: a moment hit on the same id whose `t_ms` lies inside
 *    the target window ± `slackMs` earns the full grade; a lesson hit, or a
 *    moment outside the window, earns grade − 1 (minimum 1) — the right
 *    lesson at the wrong time;
 *  - a lesson target (`lessonKinds`): a moment inside it counts as the lesson;
 *  - anything else: same kind and same id. A target id of `?`/empty never
 *    matches (an unlabelled target).
 *
 * Negatives (`expect_empty`) are scored apart as a false-positive rate: the
 * share that were {@see RunRecord::answered()}.
 *
 * Differences from the script, all opt-in through the constructor: the
 * lesson kinds include `lesson` (the v2 name) beside `step`; `gatedStatuses`
 * defaults to silver AND gold (the script skipped gold, which no set had yet;
 * pass ['silver'] for the script's behaviour, null for its
 * `--include-needs-label`); a `course:` slice is added; values are not rounded.
 */
final class Metrics
{
    /**
     * @param  list<string>  $lessonKinds
     * @param  list<string>|null  $gatedStatuses  label statuses scored; null = all
     * @param  list<string>|null  $layers  item layers scored; null = all
     */
    public function __construct(
        public readonly int $k = 10,
        public readonly int $slackMs = 15_000,
        public readonly array $lessonKinds = ['step', 'lesson'],
        public readonly ?array $gatedStatuses = ['silver', 'gold'],
        public readonly ?array $layers = null,
    ) {}

    /**
     * @param  iterable<GoldenItem>  $golden
     * @param  iterable<RunRecord>  $records
     */
    public function evaluate(iterable $golden, iterable $records, ?string $fingerprint = null): EvalReport
    {
        $runs = [];
        foreach ($records as $record) {
            $runs[$record->gid] = $record;
        }

        $perQuery = [];
        $slices = [];
        $negatives = [];
        $latencies = [];

        foreach ($records as $record) {
            if ($record->latencyMs !== null) {
                $latencies[] = $record->latencyMs;
            }
        }

        foreach ($golden as $item) {
            $record = $runs[$item->gid] ?? new RunRecord($item->gid, []);

            if ($item->expectEmpty) {
                $negatives[] = $record->answered() ? 1.0 : 0.0;

                continue;
            }

            if ($this->gatedStatuses !== null && ! in_array($item->labelStatus, $this->gatedStatuses, true)) {
                continue;
            }

            if ($this->layers !== null && ! in_array($item->layer, $this->layers, true)) {
                continue;
            }

            $scores = $this->score($item, $record->hits);
            $perQuery[$item->gid] = $scores;

            $keys = ['all', "intent:{$item->intent}", "caller:{$item->caller}", "label:{$item->labelStatus}"];

            if ($item->course !== null && $item->course !== '') {
                $keys[] = "course:{$item->course}";
            }

            foreach ($item->phenomena as $phenomenon) {
                $keys[] = "phen:{$phenomenon}";
            }

            foreach ($keys as $key) {
                $slices[$key][] = $scores;
            }
        }

        ksort($slices, SORT_STRING);

        return new EvalReport(
            k: $this->k,
            nScored: count($perQuery),
            falsePositiveRate: $negatives === [] ? null : array_sum($negatives) / count($negatives),
            negatives: count($negatives),
            slices: array_map(static fn (array $rows): array => [
                'n' => count($rows),
                'recall' => self::mean($rows, 'recall'),
                'hit1' => self::mean($rows, 'hit1'),
                'mrr' => self::mean($rows, 'mrr'),
                'ndcg' => self::mean($rows, 'ndcg'),
            ], $slices),
            perQuery: $perQuery,
            latency: EvalReport::percentiles($latencies),
            fingerprint: $fingerprint,
        );
    }

    /**
     * One query's scores.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array{recall: float, hit1: float, mrr: float, ndcg: float}
     */
    public function score(GoldenItem $item, array $hits): array
    {
        $targets = array_values(array_filter(
            $item->relevant,
            static fn (array $target): bool => ! in_array($target['id'], ['?', ''], true),
        ));

        $gains = [];
        $credited = [];

        foreach (array_slice(array_values($hits), 0, $this->k) as $hit) {
            $best = 0;
            $bestIndex = null;

            foreach ($targets as $i => $target) {
                if (isset($credited[$i])) {
                    continue;
                }

                $credit = $this->credit($target, $hit);

                if ($credit > $best) {
                    $best = $credit;
                    $bestIndex = $i;
                }
            }

            if ($bestIndex !== null) {
                $credited[$bestIndex] = true;
            }

            $gains[] = $best;
        }

        $grades = array_map(static fn (array $target): int => $target['grade'], $targets);
        rsort($grades);
        $ideal = array_slice($grades, 0, $this->k);

        $idcg = self::dcg($ideal);
        $first = null;

        foreach ($gains as $i => $gain) {
            if ($gain > 0) {
                $first = $i;
                break;
            }
        }

        return [
            'recall' => $targets === [] ? 0.0 : count($credited) / count($targets),
            'hit1' => ($gains[0] ?? 0) > 0 ? 1.0 : 0.0,
            'mrr' => $first === null ? 0.0 : 1.0 / ($first + 1),
            'ndcg' => self::dcg($gains) / ($idcg == 0.0 ? 1.0 : $idcg),
        ];
    }

    /**
     * The grade `$hit` earns against `$target` (0 when it does not match).
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $hit
     */
    public function credit(array $target, array $hit): int
    {
        $id = (string) ($target['id'] ?? '');

        if (in_array($id, ['?', ''], true) || (string) ($hit['id'] ?? '') !== $id) {
            return 0;
        }

        $targetKind = (string) $target['kind'];
        $hitKind = (string) ($hit['kind'] ?? '');
        $grade = (int) $target['grade'];

        if ($targetKind === 'moment') {
            [$from, $to] = array_values((array) ($target['t_ms'] ?? [0, 0])) + [0, 0];
            $t = $hit['t_ms'] ?? null;

            if ($hitKind === 'moment' && is_numeric($t) && $from - $this->slackMs <= $t && $t <= $to + $this->slackMs) {
                return $grade;
            }

            return ($hitKind === 'moment' || $this->isLesson($hitKind)) ? max($grade - 1, 1) : 0;
        }

        if ($this->isLesson($targetKind)) {
            return ($hitKind === 'moment' || $this->isLesson($hitKind)) ? $grade : 0;
        }

        return $hitKind === $targetKind ? $grade : 0;
    }

    private function isLesson(string $kind): bool
    {
        return in_array($kind, $this->lessonKinds, true);
    }

    /**
     * @param  list<int>  $gains
     */
    private static function dcg(array $gains): float
    {
        $sum = 0.0;

        foreach (array_values($gains) as $i => $gain) {
            $sum += (2 ** $gain - 1) / log($i + 2, 2);
        }

        return $sum;
    }

    /**
     * @param  list<array<string, float>>  $rows
     */
    private static function mean(array $rows, string $metric): float
    {
        return $rows === [] ? NAN : array_sum(array_column($rows, $metric)) / count($rows);
    }
}

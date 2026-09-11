<?php

namespace Saad\AiKit\Bench;

use JsonSerializable;

/**
 * One execution of one scenario — every turn (fresh turns AND the resume
 * records the runner appended while answering cards, in order), the
 * verdicts, the summed cost/wall, and the crash text if the run itself
 * blew up before or during its turns.
 */
final class ScenarioRun implements JsonSerializable
{
    /** @var list<TurnRecord> */
    public array $turns = [];

    /** @var list<Verdict> */
    public array $verdicts = [];

    public float $costUsd = 0.0;

    public int $wallMs = 0;

    /** Exception text if the run itself crashed (driver, seed, teardown). */
    public ?string $failure = null;

    /** Zero-based index in $turns where each scripted Turn's records begin. */
    public array $turnBoundaries = [];

    public function __construct(
        public Scenario $scenario,
        public int $attempt,
        public mixed $context = null,
    ) {}

    public function addTurn(TurnRecord $record): void
    {
        $this->turns[] = $record;
        $this->wallMs += $record->wallMs;
        $this->costUsd += $record->costUsd() ?? 0.0;
    }

    public function lastTurn(): ?TurnRecord
    {
        return $this->turns === [] ? null : $this->turns[array_key_last($this->turns)];
    }

    /** The final assistant text of the last record. */
    public function lastText(): string
    {
        return $this->lastTurn()?->text ?? '';
    }

    /** Every record's text, joined by blank lines (empty texts skipped). */
    public function allText(): string
    {
        return implode("\n\n", array_filter(
            array_map(fn (TurnRecord $record): string => $record->text, $this->turns),
            fn (string $text): bool => trim($text) !== '',
        ));
    }

    /**
     * Tool names across the whole run, in call order.
     *
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_merge([], ...array_map(fn (TurnRecord $record): array => $record->toolNames(), $this->turns));
    }

    /**
     * Every tool call across the run, in call order.
     *
     * @return list<array{id: ?string, name: string, arguments: ?array<string, mixed>, status: string, result: ?string, error: bool}>
     */
    public function toolCalls(): array
    {
        return array_merge([], ...array_map(fn (TurnRecord $record): array => $record->toolCalls, $this->turns));
    }

    /**
     * Every pending card raised across the run (including ones later decided).
     *
     * @return list<array{id: string, kind: string, title: ?string, options: ?array<int, mixed>, fields: ?array<int|string, mixed>}>
     */
    public function cards(?string $kind = null): array
    {
        $cards = array_merge([], ...array_map(fn (TurnRecord $record): array => $record->pendingCards, $this->turns));

        if ($kind === null) {
            return $cards;
        }

        return array_values(array_filter($cards, fn (array $card): bool => ($card['kind'] ?? null) === $kind));
    }

    /** True when no verdict failed and the run did not crash. */
    public function passed(): bool
    {
        if ($this->failure !== null) {
            return false;
        }

        foreach ($this->verdicts as $verdict) {
            if ($verdict->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Verdict>
     */
    public function failedVerdicts(): array
    {
        return array_values(array_filter($this->verdicts, fn (Verdict $verdict): bool => $verdict->failed()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scenario' => $this->scenario->toArray(),
            'attempt' => $this->attempt,
            'passed' => $this->passed(),
            'failure' => $this->failure,
            'costUsd' => round($this->costUsd, 6),
            'wallMs' => $this->wallMs,
            'turns' => array_map(fn (TurnRecord $record): array => $record->toArray(), $this->turns),
            'turnBoundaries' => $this->turnBoundaries,
            'verdicts' => array_map(fn (Verdict $verdict): array => $verdict->toArray(), $this->verdicts),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

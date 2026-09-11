<?php

namespace Saad\AiKit\Bench;

use JsonSerializable;

/**
 * Everything the bench keeps about one driver call (a fresh turn or a
 * resume): the final text, the raw buffer events, the folded tool calls,
 * the cards the turn paused on, the aggregated usage and the wall time.
 * Cost is whatever the app's usage rows reported — never a static-table
 * estimate (DECISIONS.md #26c); an unreported cost is null.
 *
 * @phpstan-type ToolCallShape array{id: ?string, name: string, arguments: ?array<string, mixed>, status: string, result: ?string, error: bool}
 * @phpstan-type PendingCardShape array{id: string, kind: string, title: ?string, options: ?array<int, mixed>, fields: ?array<int|string, mixed>}
 * @phpstan-type UsageShape array{cost_usd: ?float, prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, invocations: int, duration_ms: ?int}
 */
final readonly class TurnRecord implements JsonSerializable
{
    public const STATUS_DONE = 'done';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_CANCELLED = 'cancelled';

    public const CARD_APPROVAL = 'approval';

    public const CARD_QUESTION = 'question';

    /**
     * @param  string  $status  done|paused|error|cancelled
     * @param  string  $text  final assistant text shown to the user ('' if none)
     * @param  list<array{event: string, data: array<string, mixed>}>  $events  raw buffer events
     * @param  list<ToolCallShape>  $toolCalls
     * @param  list<PendingCardShape>  $pendingCards  kind: approval|question
     * @param  UsageShape  $usage  aggregated over the turn's model invocations
     * @param  array<string, mixed>  $meta  driver-defined extras (applied, undo, steps…)
     */
    public function __construct(
        public string $turnId,
        public ?string $conversationId,
        public string $status,
        public string $text = '',
        public array $events = [],
        public array $toolCalls = [],
        public array $pendingCards = [],
        public ?string $error = null,
        public int $wallMs = 0,
        public array $usage = [
            'cost_usd' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'reasoning_tokens' => 0,
            'invocations' => 0,
            'duration_ms' => null,
        ],
        public array $meta = [],
    ) {}

    /**
     * Fill the usage shape's missing keys so consumers can index it blindly.
     *
     * @param  array<string, mixed>  $usage
     * @return UsageShape
     */
    public static function normalizeUsage(array $usage): array
    {
        return [
            'cost_usd' => isset($usage['cost_usd']) ? (float) $usage['cost_usd'] : null,
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
            'invocations' => (int) ($usage['invocations'] ?? 0),
            'duration_ms' => isset($usage['duration_ms']) ? (int) $usage['duration_ms'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            turnId: (string) ($data['turnId'] ?? $data['turn_id'] ?? ''),
            conversationId: $data['conversationId'] ?? $data['conversation_id'] ?? null,
            status: (string) ($data['status'] ?? self::STATUS_DONE),
            text: (string) ($data['text'] ?? ''),
            events: array_values($data['events'] ?? []),
            toolCalls: array_values($data['toolCalls'] ?? $data['tool_calls'] ?? []),
            pendingCards: array_values($data['pendingCards'] ?? $data['pending_cards'] ?? []),
            error: $data['error'] ?? null,
            wallMs: (int) ($data['wallMs'] ?? $data['wall_ms'] ?? 0),
            usage: self::normalizeUsage($data['usage'] ?? []),
            meta: $data['meta'] ?? [],
        );
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function costUsd(): ?float
    {
        $cost = $this->usage['cost_usd'] ?? null;

        return $cost === null ? null : (float) $cost;
    }

    /**
     * @return list<PendingCardShape>
     */
    public function cardsOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->pendingCards,
            fn (array $card): bool => ($card['kind'] ?? null) === $kind,
        ));
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_values(array_map(fn (array $call): string => (string) $call['name'], $this->toolCalls));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'turnId' => $this->turnId,
            'conversationId' => $this->conversationId,
            'status' => $this->status,
            'text' => $this->text,
            'events' => $this->events,
            'toolCalls' => $this->toolCalls,
            'pendingCards' => $this->pendingCards,
            'error' => $this->error,
            'wallMs' => $this->wallMs,
            'usage' => self::normalizeUsage($this->usage),
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

namespace Saad\AiKit\Testing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Saad\AiKit\Bench\TurnDriver;
use Saad\AiKit\Bench\TurnRecord;

/**
 * A {@see TurnDriver} that replays scripted {@see TurnRecord}s in order —
 * `run()` and `decide()` both pop from the same queue, exactly as the
 * runner interleaves them — and records every call it received, so a test
 * can assert the prompt, the continued conversation id, the model and the
 * decisions the runner sent. A responder closure can take over when the
 * queue is empty (or from the start); with neither, an unexpected call
 * throws, which is also how tests exercise the runner's crash isolation.
 */
class FakeTurnDriver implements TurnDriver
{
    /** @var list<TurnRecord> */
    private array $queue = [];

    /** @var (Closure(string, array<string, mixed>): TurnRecord)|null */
    private ?Closure $responder = null;

    /** @var list<array{method: string, actor: Authenticatable, prompt?: string, files?: array<int, mixed>, decisions?: array<string, mixed>, conversationId: ?string, model: ?string}> */
    public array $calls = [];

    /**
     * @param  list<TurnRecord>  $records
     */
    public function __construct(array $records = [])
    {
        $this->queue = array_values($records);
    }

    public function queue(TurnRecord ...$records): static
    {
        foreach ($records as $record) {
            $this->queue[] = $record;
        }

        return $this;
    }

    /**
     * @param  Closure(string, array<string, mixed>): TurnRecord  $responder  fn(method, call) => record
     */
    public function respondWith(Closure $responder): static
    {
        $this->responder = $responder;

        return $this;
    }

    public function run(Authenticatable $actor, string $prompt, array $files = [], ?string $conversationId = null, ?string $model = null): TurnRecord
    {
        $call = compact('actor', 'prompt', 'files', 'conversationId', 'model') + ['method' => 'run'];
        $this->calls[] = $call;

        return $this->next('run', $call);
    }

    public function decide(Authenticatable $actor, string $conversationId, array $decisions, ?string $model = null): TurnRecord
    {
        $call = compact('actor', 'conversationId', 'decisions', 'model') + ['method' => 'decide'];
        $this->calls[] = $call;

        return $this->next('decide', $call);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    /**
     * A quick `done` record for scripts that only care about text/tools.
     *
     * @param  list<array{id?: ?string, name: string, arguments?: ?array<string, mixed>, status?: string, result?: ?string, error?: bool}>  $toolCalls
     * @param  array<string, mixed>  $usage
     */
    public static function done(string $text, array $toolCalls = [], ?float $costUsd = null, string $conversationId = 'conv-1', array $usage = [], int $wallMs = 10): TurnRecord
    {
        return new TurnRecord(
            turnId: 'turn-'.bin2hex(random_bytes(3)),
            conversationId: $conversationId,
            status: TurnRecord::STATUS_DONE,
            text: $text,
            toolCalls: array_map(fn (array $call): array => [
                'id' => $call['id'] ?? null,
                'name' => $call['name'],
                'arguments' => $call['arguments'] ?? null,
                'status' => $call['status'] ?? 'done',
                'result' => $call['result'] ?? null,
                'error' => $call['error'] ?? false,
            ], $toolCalls),
            wallMs: $wallMs,
            usage: TurnRecord::normalizeUsage($usage + ['cost_usd' => $costUsd, 'invocations' => 1]),
        );
    }

    /**
     * A `paused` record carrying the given cards.
     *
     * @param  list<array{id: string, kind?: string, title?: ?string, options?: ?array<int, mixed>, fields?: ?array<int|string, mixed>}>  $cards
     */
    public static function paused(array $cards, string $text = '', string $conversationId = 'conv-1', ?float $costUsd = null): TurnRecord
    {
        return new TurnRecord(
            turnId: 'turn-'.bin2hex(random_bytes(3)),
            conversationId: $conversationId,
            status: TurnRecord::STATUS_PAUSED,
            text: $text,
            pendingCards: array_map(fn (array $card): array => [
                'id' => $card['id'],
                'kind' => $card['kind'] ?? TurnRecord::CARD_APPROVAL,
                'title' => $card['title'] ?? null,
                'options' => $card['options'] ?? null,
                'fields' => $card['fields'] ?? null,
            ], $cards),
            wallMs: 10,
            usage: TurnRecord::normalizeUsage(['cost_usd' => $costUsd, 'invocations' => 1]),
        );
    }

    public static function errored(string $error, string $conversationId = 'conv-1'): TurnRecord
    {
        return new TurnRecord(
            turnId: 'turn-'.bin2hex(random_bytes(3)),
            conversationId: $conversationId,
            status: TurnRecord::STATUS_ERROR,
            error: $error,
            wallMs: 10,
        );
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function next(string $method, array $call): TurnRecord
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        if ($this->responder !== null) {
            return ($this->responder)($method, $call);
        }

        throw new RuntimeException("FakeTurnDriver: no scripted record left for {$method}().");
    }
}

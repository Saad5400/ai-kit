<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * What the engine returned for one golden item: the ranked hits, best first
 * (`{kind, id, t_ms?}` in the golden set's vocabulary), whether the engine
 * called the page confident, and how long it took.
 */
final class RunRecord
{
    /**
     * @param  list<array{kind: string, id: string, t_ms?: int|null}>  $hits
     * @param  array<string, mixed>  $meta  anything else worth keeping (embed/rerank calls, variant)
     */
    public function __construct(
        public readonly string $gid,
        public readonly array $hits,
        public readonly ?bool $confident = null,
        public readonly ?float $latencyMs = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            gid: (string) $data['gid'],
            hits: array_values($data['hits'] ?? []),
            confident: isset($data['confident']) ? (bool) $data['confident'] : null,
            latencyMs: isset($data['latency_ms']) ? (float) $data['latency_ms'] : null,
            meta: (array) ($data['meta'] ?? []),
        );
    }

    /**
     * @return list<self>
     */
    public static function fromJsonl(string $jsonl): array
    {
        $records = [];

        foreach (preg_split('/\r\n|\n|\r/', $jsonl) ?: [] as $line) {
            if (trim($line) !== '' && is_array($data = json_decode($line, true))) {
                $records[] = self::fromArray($data);
            }
        }

        return $records;
    }

    public function withLatency(float $latencyMs): self
    {
        return new self($this->gid, $this->hits, $this->confident, $latencyMs, $this->meta);
    }

    /**
     * Whether a NEGATIVE item (one expecting no answer) was answered anyway:
     * the engine's own confidence when it reported one, else "returned
     * anything at all".
     */
    public function answered(): bool
    {
        return $this->confident ?? ($this->hits !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'gid' => $this->gid,
            'hits' => $this->hits,
            'confident' => $this->confident,
            'latency_ms' => $this->latencyMs,
            'meta' => $this->meta === [] ? null : $this->meta,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param  list<self>  $records
     */
    public static function toJsonl(array $records): string
    {
        return implode('', array_map(
            static fn (self $record): string => json_encode($record->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            $records,
        ));
    }
}

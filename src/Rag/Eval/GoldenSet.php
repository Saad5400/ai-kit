<?php

namespace Saad\AiKit\Rag\Eval;

use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * A golden set: labelled queries, loaded from JSON Lines.
 *
 * @implements IteratorAggregate<int, GoldenItem>
 */
final class GoldenSet implements Countable, IteratorAggregate
{
    /**
     * @param  list<GoldenItem>  $items
     */
    public function __construct(public readonly array $items) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Golden set not found: {$path}");
        }

        return self::fromJsonl((string) file_get_contents($path));
    }

    public static function fromJsonl(string $jsonl): self
    {
        $items = [];

        foreach (preg_split('/\r\n|\n|\r/', $jsonl) ?: [] as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (! is_array($data) || ! isset($data['gid'])) {
                throw new InvalidArgumentException('Golden set line '.($number + 1).' is not a JSON object with a gid.');
            }

            $items[] = GoldenItem::fromArray($data);
        }

        return new self($items);
    }

    /**
     * @param  Closure(GoldenItem): bool  $keep
     */
    public function filter(Closure $keep): self
    {
        return new self(array_values(array_filter($this->items, $keep)));
    }

    public function find(string $gid): ?GoldenItem
    {
        foreach ($this->items as $item) {
            if ($item->gid === $gid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Run every item through the app's engine. The closure gets the item and
     * returns the ranked hits as a {@see RunRecord}; wall time is measured
     * here when the closure does not set it.
     *
     * @param  Closure(GoldenItem): RunRecord  $engine
     * @return list<RunRecord>
     */
    public function run(Closure $engine): array
    {
        $records = [];

        foreach ($this->items as $item) {
            $started = hrtime(true);
            $record = $engine($item);

            $records[] = $record->latencyMs !== null
                ? $record
                : $record->withLatency((hrtime(true) - $started) / 1e6);
        }

        return $records;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}

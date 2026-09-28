<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Prices the generations an interrupted turn left pending once OpenRouter's
 * generation stats exist, then hands the turn's total to the app's
 * {@see InterruptedSpendHandler} — queued by {@see InterruptedSpend} for
 * whatever its synchronous fast path could not price.
 *
 * Each run takes ONE look per still-unpriced id. Ids that price are
 * budgeted at once (keyed per generation id, so a repeat never counts them
 * twice). While any remain it queues its successor with the next delay of
 * `ai-kit.spend.retry_delays_seconds` (the payload carries what is already
 * priced, so a successor never re-fetches it); on the last attempt it gives
 * up — a warning, never an exception — and hands over whatever DID price.
 *
 * `$tries` only covers a handler that throws: the pricing loop never does.
 */
class ResolveInterruptedSpend implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<string>  $pendingGenerationIds  the ids this chain prices
     * @param  array<string, mixed>  $context  handed to the handler untouched
     * @param  array<string, float>  $resolved  already priced: id => USD
     * @param  list<string>  $settledGenerationIds
     * @param  list<string>  $allGenerationIds  the settlement's full generation set (the once-guard's fingerprint)
     */
    public function __construct(
        public string $turnId,
        public array $pendingGenerationIds,
        public array $context = [],
        public array $resolved = [],
        public float $settledCostUsd = 0.0,
        public array $settledGenerationIds = [],
        public array $allGenerationIds = [],
        public int $attempt = 1,
    ) {}

    public function handle(InterruptedSpend $spend, GenerationCostResolver $resolver): void
    {
        $resolved = $this->resolved;

        foreach ($this->pendingGenerationIds as $id) {
            if (array_key_exists($id, $resolved)) {
                continue;
            }

            $cost = $resolver->fetch($id);

            if ($cost !== null) {
                $resolved[$id] = $cost;
                $spend->recordResolved($id, $cost);
            }
        }

        $unresolved = array_values(array_diff($this->pendingGenerationIds, array_keys($resolved)));
        $delays = $spend->retryDelays();

        if ($unresolved !== [] && $this->attempt < count($delays)) {
            $spend->queue(new self(
                $this->turnId,
                $this->pendingGenerationIds,
                $this->context,
                $resolved,
                $this->settledCostUsd,
                $this->settledGenerationIds,
                $this->allGenerationIds,
                $this->attempt + 1,
            ), $delays[$this->attempt]);

            return;
        }

        if ($unresolved !== []) {
            $spend->gaveUp($this->turnId, $unresolved, $this->attempt);
        }

        $spend->finish(
            $this->turnId,
            $this->settledCostUsd,
            $this->settledGenerationIds,
            $resolved,
            $this->allGenerationIds !== [] ? $this->allGenerationIds : array_values(array_unique([...$this->settledGenerationIds, ...$this->pendingGenerationIds])),
            $this->context,
        );
    }
}

<?php

namespace Saad\AiKit\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Saad\AiKit\Gateway\GenerationCostResolver;
use Saad\AiKit\Gateway\GenerationCostUnavailable;
use Throwable;

/**
 * Prices a turn's pending generations once OpenRouter's generation stats
 * exist, then fires {@see Events\InterruptedSpendResolved} through
 * {@see InterruptedSpend::finish()}.
 *
 * Each run takes ONE look per still-unpriced id; ids that price are
 * budgeted at once (keyed per generation id). While any remain it queues
 * its successor with the next delay of `ai-kit.spend.retry_delays_seconds`
 * (the payload carries what is already priced, so a successor never
 * re-fetches it); on the last attempt it gives up — a warning, never an
 * exception — and reports whatever DID price.
 *
 * `$tries` only covers a listener that throws: the pricing loop never does.
 */
class ResolveInterruptedSpend implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  int  $turnUsageId  the turn's usage row
     * @param  list<string>  $pendingGenerationIds  every generation this settlement prices
     * @param  array<string, float>  $resolved  already priced: id => USD
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $turnUsageId,
        public array $pendingGenerationIds,
        public array $resolved = [],
        public ?string $turnId = null,
        public array $meta = [],
        public int $attempt = 1,
    ) {}

    public function handle(InterruptedSpend $spend, GenerationCostResolver $resolver): void
    {
        $resolved = $this->resolved;
        $delays = $spend->retryDelays();
        $last = $this->attempt >= count($delays);

        try {
            foreach ($this->pendingGenerationIds as $id) {
                if (array_key_exists($id, $resolved)) {
                    continue;
                }

                // A zero cost with tokens counted reads as "not yet" — until
                // the last look, when it is taken at its word.
                $cost = $resolver->fetch($id, acceptZero: $last);

                if ($cost !== null) {
                    $resolved[$id] = $cost;
                    $spend->recordResolved($id, $cost);
                }
            }
        } catch (GenerationCostUnavailable $e) {
            $spend->unavailable($this->turnUsageId, array_values(array_diff($this->pendingGenerationIds, array_keys($resolved))), $e);
            $this->settle($spend, $resolved);

            return;
        }

        $unresolved = array_values(array_diff($this->pendingGenerationIds, array_map('strval', array_keys($resolved))));

        if ($unresolved !== [] && ! $last) {
            try {
                $spend->queue(new self(
                    $this->turnUsageId,
                    $this->pendingGenerationIds,
                    $resolved,
                    $this->turnId,
                    $this->meta,
                    $this->attempt + 1,
                ), $delays[$this->attempt]);

                return;
            } catch (Throwable $e) {
                report($e);

                $spend->gaveUp($this->turnUsageId, $unresolved, $this->attempt, 'the next attempt could not be queued', $resolver->lastStatus);
                $this->settle($spend, $resolved);

                return;
            }
        }

        $spend->gaveUp($this->turnUsageId, $unresolved, $this->attempt, lastStatus: $resolver->lastStatus);

        $this->settle($spend, $resolved);
    }

    /**
     * Fire the event; another worker still holding the settlement's lock
     * (a redelivery racing its original) means try again shortly.
     *
     * @param  array<string, float>  $resolved
     */
    protected function settle(InterruptedSpend $spend, array $resolved): void
    {
        if ($spend->finish($this->turnUsageId, $resolved, $this->pendingGenerationIds, $this->turnId, $this->meta) === InterruptedSpend::BUSY) {
            $this->release(60);
        }
    }
}

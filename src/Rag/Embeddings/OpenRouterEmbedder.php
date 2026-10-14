<?php

namespace Saad\AiKit\Rag\Embeddings;

use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Rag\Support\OpenRouterEndpoint;
use Saad\AiKit\Rag\Support\RetrievalModels;

/**
 * The `embeddings` lane over OpenRouter's OpenAI-compatible
 * `POST {base}/embeddings` endpoint.
 *
 * Geometry is enforced, never assumed: every returned vector must have
 * exactly {@see dimensions()} floats. When the configured dimensions are
 * below the model's native size, the driver either asks the provider for
 * them (`dimensions` request field — only for a registry entry that declares
 * `dimensions_parameter`) or truncates and re-normalises the native vector
 * itself, which is valid only for a Matryoshka-trained model (`matryoshka`).
 * Anything else is a configuration error and throws, because a vector of the
 * wrong geometry would corrupt an index silently. At the model's native size
 * the request carries no `dimensions` field at all — byte-identical to what
 * catodemy's own driver sent before the move.
 *
 * Inputs are sent in batches of `batch_size`; output order follows each
 * row's `index`. Every request reports its cost through {@see RetrievalSpend}.
 */
class OpenRouterEmbedder implements Embedder
{
    /**
     * @param  string|null  $queryInstruction  null → the registry entry's
     */
    public function __construct(
        private readonly OpenRouterEndpoint $endpoint,
        private readonly Dispatcher $events,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly ?string $queryInstruction = null,
        private readonly int $batchSize = 100,
        private readonly int $timeoutSeconds = 60,
        private readonly int $attempts = 2,
    ) {}

    /** Built from the `ai-kit.embeddings` lane. */
    public static function fromConfig(): self
    {
        $config = (array) config('ai-kit.embeddings', []);

        return new self(
            endpoint: OpenRouterEndpoint::make(),
            events: app(Dispatcher::class),
            model: (string) ($config['model'] ?? 'openai/text-embedding-3-small'),
            dimensions: (int) ($config['dimensions'] ?? 1536),
            queryInstruction: isset($config['query_instruction']) && $config['query_instruction'] !== ''
                ? (string) $config['query_instruction']
                : null,
            batchSize: max(1, (int) ($config['batch_size'] ?? 100)),
            timeoutSeconds: max(1, (int) ($config['timeout_seconds'] ?? 60)),
            attempts: max(1, (int) ($config['attempts'] ?? 2)),
        );
    }

    public function embedDocuments(array $texts): array
    {
        return $this->embed(array_values($texts));
    }

    public function embedQueries(array $texts): array
    {
        $instruction = $this->queryInstruction();

        return $this->embed(array_map(
            static fn (string $text): string => $instruction === '' ? $text : $instruction.$text,
            array_values($texts),
        ));
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function geometry(): string
    {
        $instruction = $this->queryInstruction();

        return $this->model.':'.$this->dimensions
            .($instruction === '' ? '' : '|qi:'.substr(hash('sha256', $instruction), 0, 12));
    }

    /** The prefix queries are embedded with: the lane's, else the registry entry's, else none. */
    public function queryInstruction(): string
    {
        return $this->queryInstruction
            ?? (string) (RetrievalModels::find($this->model)['query_instruction'] ?? '');
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            array_push($vectors, ...$this->embedBatch($batch));
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(array $batch): array
    {
        $entry = RetrievalModels::find($this->model) ?? [];
        $native = isset($entry['dimensions']) ? (int) $entry['dimensions'] : null;
        $askProvider = $native !== null && $this->dimensions < $native && ($entry['dimensions_parameter'] ?? false) === true;

        $body = ['model' => $this->model, 'input' => $batch];

        if ($askProvider) {
            $body['dimensions'] = $this->dimensions;
        }

        $started = hrtime(true);

        $response = $this->endpoint->request()
            ->timeout($this->timeoutSeconds)
            ->retry($this->attempts, 250, throw: false)
            ->post($this->endpoint->url('embeddings'), $body);

        $elapsedMs = (int) round((hrtime(true) - $started) / 1e6);

        if ($response->failed()) {
            $this->report(count($batch), 0, null, $elapsedMs, failed: true);

            throw new RuntimeException(
                "Embeddings request failed (HTTP {$response->status()}, model {$this->model}): ".$response->body()
            );
        }

        $rows = $response->json('data');

        $this->report(
            count($batch),
            (int) ($response->json('usage.prompt_tokens') ?? $response->json('usage.total_tokens') ?? 0),
            OpenRouterEndpoint::cost($response->json('usage')),
            $elapsedMs,
        );

        if (! is_array($rows) || count($rows) !== count($batch)) {
            throw new RuntimeException(
                'Embeddings response shape unexpected: expected '.count($batch).' vectors, got '
                .(is_array($rows) ? count($rows) : 'none').'.'
            );
        }

        $ordered = [];

        foreach (array_values($rows) as $position => $row) {
            $index = is_array($row) && isset($row['index']) ? (int) $row['index'] : $position;
            $embedding = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($embedding)) {
                throw new RuntimeException('Embeddings response is missing an embedding vector.');
            }

            $ordered[$index] = $this->fit(array_map(static fn ($v): float => (float) $v, array_values($embedding)), $entry);
        }

        ksort($ordered);

        return array_values($ordered);
    }

    /**
     * Bring one vector to the configured geometry, or refuse.
     *
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $entry
     * @return list<float>
     */
    private function fit(array $vector, array $entry): array
    {
        $length = count($vector);

        if ($length > $this->dimensions && ($entry['matryoshka'] ?? false) === true) {
            return Vectors::truncate($vector, $this->dimensions);
        }

        if ($length !== $this->dimensions) {
            throw new RuntimeException(
                "Embedding geometry mismatch: {$this->model} returned {$length} dimensions, the lane is configured "
                ."for {$this->dimensions}, and the model is not registered as Matryoshka-truncatable "
                .'(ai-kit.catalog.retrieval_models).'
            );
        }

        return $vector;
    }

    private function report(int $inputs, int $tokens, ?float $cost, int $elapsedMs, bool $failed = false): void
    {
        $this->events->dispatch(new RetrievalSpend(
            lane: 'embeddings',
            model: $this->model,
            requests: 1,
            inputs: $inputs,
            tokens: $tokens,
            costUsd: $cost,
            durationMs: $elapsedMs,
            failed: $failed,
        ));
    }
}

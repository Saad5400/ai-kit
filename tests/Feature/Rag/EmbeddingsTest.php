<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Saad\AiKit\Rag\Embeddings\FakeEmbedder;
use Saad\AiKit\Rag\Embeddings\OpenRouterEmbedder;
use Saad\AiKit\Rag\Embeddings\Vectors;
use Saad\AiKit\Rag\Events\RetrievalSpend;

/** A fake OpenRouter embeddings answer: one vector of `$length` per input, rows shuffled. */
function embeddingsAnswer(int $length, ?float $cost = 0.000001): Closure
{
    return function (Request $request) use ($length, $cost) {
        $rows = [];

        foreach ($request['input'] as $i => $text) {
            $vector = array_fill(0, $length, 0.0);
            $vector[$i % $length] = 3.0;
            $vector[($i + 1) % $length] = 4.0;
            $rows[] = ['index' => $i, 'embedding' => $vector];
        }

        return Http::response([
            'data' => array_reverse($rows),
            'usage' => array_filter(['prompt_tokens' => 7 * count($rows), 'cost' => $cost], fn ($v) => $v !== null),
        ]);
    };
}

function laneEmbedder(array $overrides = []): OpenRouterEmbedder
{
    config(['ai-kit.embeddings' => array_merge(config('ai-kit.embeddings'), $overrides)]);

    return OpenRouterEmbedder::fromConfig();
}

it('ships today\'s catodemy embedding geometry as the lane default', function () {
    $embedder = OpenRouterEmbedder::fromConfig();

    expect($embedder->model())->toBe('openai/text-embedding-3-small')
        ->and($embedder->dimensions())->toBe(1536)
        // The exact `model:dims` string catodemy's query cache already keys on.
        ->and($embedder->geometry())->toBe('openai/text-embedding-3-small:1536');
});

it('sends no dimensions field at the native size, keeps input order, and reports spend', function () {
    Event::fake([RetrievalSpend::class]);
    Http::fake(['*/embeddings' => embeddingsAnswer(1536)]);

    $vectors = laneEmbedder()->embedDocuments(['a', 'b', 'c']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/embeddings'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->data() === ['model' => 'openai/text-embedding-3-small', 'input' => ['a', 'b', 'c']]);

    expect($vectors)->toHaveCount(3)
        ->and($vectors[0][0])->toBe(3.0)
        ->and($vectors[2][2])->toBe(3.0);

    Event::assertDispatched(RetrievalSpend::class, fn (RetrievalSpend $e) => $e->lane === 'embeddings'
        && $e->inputs === 3 && $e->tokens === 21 && $e->costUsd === 0.000001 && ! $e->failed);
});

it('honours the provider url from the laravel/ai provider config', function () {
    config(['ai.providers.openrouter.url' => 'https://proxy.test/v1/']);
    Http::fake(['proxy.test/*' => embeddingsAnswer(1536)]);

    laneEmbedder()->embedDocuments(['x']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://proxy.test/v1/embeddings');
});

it('batches large inputs and stitches them back in order', function () {
    Http::fake(['*/embeddings' => embeddingsAnswer(1536)]);

    $vectors = laneEmbedder(['batch_size' => 2])->embedDocuments(['a', 'b', 'c', 'd', 'e']);

    Http::assertSentCount(3);
    expect($vectors)->toHaveCount(5)->and($vectors[4][0])->toBe(3.0);
});

it('asks the provider for a smaller Matryoshka size when the model honours the field', function () {
    Http::fake(['*/embeddings' => embeddingsAnswer(256)]);

    laneEmbedder(['model' => 'openai/text-embedding-3-large', 'dimensions' => 256])->embedDocuments(['a']);

    Http::assertSent(fn (Request $request) => $request['dimensions'] === 256);
});

it('truncates and re-normalises a Matryoshka vector itself when the provider will not', function () {
    Http::fake(['*/embeddings' => embeddingsAnswer(4096)]);

    $embedder = laneEmbedder(['model' => 'qwen/qwen3-embedding-8b', 'dimensions' => 1536]);
    $vector = $embedder->embedDocuments(['a'])[0];

    Http::assertSent(fn (Request $request) => ! isset($request['dimensions']));
    expect($vector)->toHaveCount(1536)
        ->and(array_sum(array_map(fn ($v) => $v * $v, $vector)))->toEqualWithDelta(1.0, 1e-9);
});

it('applies the query instruction to queries only, and keys the geometry on it', function () {
    Http::fake(['*/embeddings' => embeddingsAnswer(4096)]);

    $embedder = laneEmbedder(['model' => 'qwen/qwen3-embedding-8b', 'dimensions' => 1536]);
    $embedder->embedQueries(['حلقة for']);
    $embedder->embedDocuments(['حلقة for']);

    $inputs = collect(Http::recorded())->map(fn ($pair) => $pair[0]['input'][0])->all();

    expect($inputs[0])->toStartWith('Instruct: ')->toEndWith("\nQuery: حلقة for")
        ->and($inputs[1])->toBe('حلقة for')
        ->and($embedder->geometry())->toStartWith('qwen/qwen3-embedding-8b:1536|qi:');
});

it('lets the lane override the registry instruction, and uses none for a symmetric model', function () {
    expect(laneEmbedder(['model' => 'qwen/qwen3-embedding-8b', 'query_instruction' => 'Q: '])->queryInstruction())->toBe('Q: ')
        ->and(laneEmbedder(['model' => 'openai/text-embedding-3-small', 'query_instruction' => null])->queryInstruction())->toBe('');
});

it('refuses a vector of the wrong geometry instead of corrupting an index', function () {
    Http::fake(['*/embeddings' => embeddingsAnswer(768)]);

    laneEmbedder(['model' => 'vendor/unknown-embedder', 'dimensions' => 1536])->embedDocuments(['a']);
})->throws(RuntimeException::class, 'geometry mismatch');

it('throws on an HTTP failure after its attempts, and reports the failed call', function () {
    Event::fake([RetrievalSpend::class]);
    Http::fake(['*/embeddings' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => laneEmbedder()->embedDocuments(['a']))->toThrow(RuntimeException::class, 'HTTP 500');

    Http::assertSentCount(2);
    Event::assertDispatched(RetrievalSpend::class, fn (RetrievalSpend $e) => $e->failed);
});

it('throws on a short response rather than misaligning vectors', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => array_fill(0, 1536, 0.1)]]])]);

    laneEmbedder()->embedDocuments(['a', 'b']);
})->throws(RuntimeException::class, 'expected 2 vectors, got 1');

it('makes no request for no input, and refuses to run without a key', function () {
    Http::fake();

    expect(laneEmbedder()->embedDocuments([]))->toBe([]);
    Http::assertNothingSent();

    config(['ai.providers.openrouter.key' => null]);
    laneEmbedder()->embedQueries(['a']);
})->throws(RuntimeException::class, 'No API key');

it('keeps the fake embedder bit-identical to catodemy\'s, deterministic and token-sensitive', function () {
    $fake = new FakeEmbedder(64);

    $a = $fake->embedDocuments(['multithreading in Java'])[0];

    // Pinned from catodemy's App\Services\Corpus\Embedders\FakeEmbedder on origin/main.
    $expected = array_fill(0, 64, 0.0);
    foreach (['multithreading', 'in', 'java'] as $token) {
        $expected[crc32($token) % 64] += 1.0;
        $expected[crc32('salt:'.$token) % 64] += 0.5;
    }
    $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $expected)));

    expect($a)->toBe(array_map(fn (float $v) => $v / $norm, $expected))
        ->and($fake->embedQueries(['multithreading in Java'])[0])->toBe($a)
        ->and(Vectors::cosine($a, $fake->embedDocuments(['java threads'])[0]))
        ->toBeGreaterThan(Vectors::cosine($a, $fake->embedDocuments(['حلقة for'])[0]))
        ->and($fake->embedDocuments([''])[0])->toBe(array_fill(0, 64, 0.0))
        ->and($fake->geometry())->toBe('fake:64');
});

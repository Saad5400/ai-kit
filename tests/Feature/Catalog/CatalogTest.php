<?php

use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Catalog\CatalogServiceProvider;
use Saad\AiKit\Catalog\CatalogSource;
use Saad\AiKit\Catalog\ConfigCatalogSource;
use Saad\AiKit\Catalog\ModelDefinition;
use Saad\AiKit\Catalog\ModelRouting;

function catalogConfig(array $models, array $extras = []): void
{
    config()->set('ai-kit.catalog', array_merge([
        'provider' => 'openrouter',
        'cheapest' => null,
        'smartest' => null,
        'models' => $models,
    ], $extras));
}

// The provider boots once with the default (empty) catalog; re-running boot
// after setting config exercises alias registration with real declarations.
function rebootCatalog(): void
{
    (new CatalogServiceProvider(app()))->boot();
}

it('builds model definitions from config', function () {
    catalogConfig([
        'google/gemini-3.5-flash' => [
            'label' => 'Gemini 3.5 Flash',
            'input_usd_per_million' => 0.30,
            'output_usd_per_million' => 2.50,
            'context_length' => 1048576,
            'capabilities' => ['tools', 'vision'],
            'fallbacks' => ['deepseek/deepseek-v4-flash'],
        ],
    ]);

    $catalog = app(CatalogSource::class);

    expect($catalog)->toBeInstanceOf(ConfigCatalogSource::class)
        ->and($catalog->models())->toHaveCount(1);

    $model = $catalog->find('google/gemini-3.5-flash');

    expect($model->label)->toBe('Gemini 3.5 Flash')
        ->and($model->inputUsdPerMillion)->toBe(0.30)
        ->and($model->contextLength)->toBe(1048576)
        ->and($model->supports('vision'))->toBeTrue()
        ->and($model->supports('audio'))->toBeFalse()
        ->and($model->fallbacks)->toBe(['deepseek/deepseek-v4-flash'])
        ->and($catalog->find('unknown/model'))->toBeNull();
});

it('estimates cost from declared prices and returns null when prices are missing', function () {
    $priced = new ModelDefinition('m', inputUsdPerMillion: 1.0, outputUsdPerMillion: 10.0);
    $unpriced = new ModelDefinition('m', inputUsdPerMillion: 1.0);

    // Reasoning tokens are already inside completion_tokens on OpenRouter.
    $usage = new Usage(promptTokens: 500_000, completionTokens: 100_000, reasoningTokens: 90_000);

    expect($priced->estimatedCostUsd($usage))->toEqualWithDelta(1.5, 0.0000001)
        ->and($unpriced->estimatedCostUsd($usage))->toBeNull();
});

it('turns a declared chain into the OpenRouter models array, itself first', function () {
    catalogConfig([
        'primary/model' => ['fallbacks' => ['backup/one', 'backup/two']],
    ]);

    expect(app(ModelRouting::class)->requestFields('primary/model'))
        ->toBe(['models' => ['primary/model', 'backup/one', 'backup/two']]);
});

it('declares nothing for a model with no chain, and for one it has never heard of', function () {
    catalogConfig(['lonely/model' => ['label' => 'Lonely']]);

    expect(app(ModelRouting::class)->requestFields('lonely/model'))->toBe([])
        ->and(app(ModelRouting::class)->requestFields('stranger/model'))->toBe([]);
});

it('declares a price cap as provider routing rather than filtering locally', function () {
    catalogConfig([
        'primary/model' => [
            'fallbacks' => ['backup/one'],
            'provider_max_price' => ['prompt' => 0.5, 'completion' => 3.25],
        ],
    ]);

    expect(app(ModelRouting::class)->requestFields('primary/model'))->toBe([
        'models' => ['primary/model', 'backup/one'],
        'provider' => ['max_price' => ['prompt' => 0.5, 'completion' => 3.25]],
    ]);
});

it('no longer clones provider entries for chain positions', function () {
    catalogConfig([
        'primary/model' => ['fallbacks' => ['backup/one', 'backup/two']],
    ]);

    rebootCatalog();

    // The chain is the provider's problem now; a cloned `ai.providers.*`
    // entry per position was only ever scaffolding for the client-side loop.
    expect(config('ai.providers.openrouter--fallback-1'))->toBeNull()
        ->and(config('ai.providers.openrouter--fallback-2'))->toBeNull();
});

it('resolves a model by its canonical slug as well as its routing id', function () {
    catalogConfig([
        'deepseek/deepseek-v4-flash' => ['canonical_slug' => 'deepseek/deepseek-v4-flash-0731'],
    ]);

    $catalog = app(CatalogSource::class);

    // An id written down while the dated pin was the routing id still
    // resolves — and comes back routing on the alias.
    expect($catalog->find('deepseek/deepseek-v4-flash-0731')?->id)->toBe('deepseek/deepseek-v4-flash')
        ->and($catalog->find('deepseek/deepseek-v4-flash')?->canonicalSlug)->toBe('deepseek/deepseek-v4-flash-0731');
});

it('feeds cheapest and smartest declarations into the provider config', function () {
    catalogConfig([], ['cheapest' => 'cheap/model', 'smartest' => 'smart/model']);

    rebootCatalog();

    expect(config('ai.providers.openrouter.models.text.cheapest'))->toBe('cheap/model')
        ->and(config('ai.providers.openrouter.models.text.smartest'))->toBe('smart/model');
});

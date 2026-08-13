<?php

use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Catalog\CatalogServiceProvider;
use Saad\AiKit\Catalog\CatalogSource;
use Saad\AiKit\Catalog\ConfigCatalogSource;
use Saad\AiKit\Catalog\FallbackChains;
use Saad\AiKit\Catalog\ModelDefinition;

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

it('builds a native-failover provider list from a declared chain', function () {
    catalogConfig([
        'primary/model' => ['fallbacks' => ['backup/one', 'backup/two']],
    ]);

    expect(app(FallbackChains::class)->providersFor('primary/model'))->toBe([
        'openrouter' => 'primary/model',
        'openrouter--fallback-1' => 'backup/one',
        'openrouter--fallback-2' => 'backup/two',
    ]);
});

it('returns only the base provider for models without a chain', function () {
    catalogConfig([]);

    expect(app(FallbackChains::class)->providersFor('lonely/model'))
        ->toBe(['openrouter' => 'lonely/model']);
});

it('registers alias provider entries cloning the base provider config', function () {
    catalogConfig([
        'primary/model' => ['fallbacks' => ['backup/one', 'backup/two']],
        'other/model' => ['fallbacks' => ['backup/one']],
    ]);

    rebootCatalog();

    $base = config('ai.providers.openrouter');

    expect(config('ai.providers.openrouter--fallback-1'))->toBe($base)
        ->and(config('ai.providers.openrouter--fallback-2'))->toBe($base)
        ->and(config('ai.providers.openrouter--fallback-3'))->toBeNull();
});

it('never overwrites an app-defined provider entry with an alias', function () {
    catalogConfig([
        'primary/model' => ['fallbacks' => ['backup/one']],
    ]);

    config()->set('ai.providers.openrouter--fallback-1', ['driver' => 'openrouter', 'key' => 'app-key']);

    rebootCatalog();

    expect(config('ai.providers.openrouter--fallback-1.key'))->toBe('app-key');
});

it('feeds cheapest and smartest declarations into the provider config', function () {
    catalogConfig([], ['cheapest' => 'cheap/model', 'smartest' => 'smart/model']);

    rebootCatalog();

    expect(config('ai.providers.openrouter.models.text.cheapest'))->toBe('cheap/model')
        ->and(config('ai.providers.openrouter.models.text.smartest'))->toBe('smart/model');
});

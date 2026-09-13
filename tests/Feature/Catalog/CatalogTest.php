<?php

use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Catalog\Catalog;
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

it('estimates a display-only cost from declared prices and returns null when prices are missing', function () {
    $priced = new ModelDefinition('m', inputUsdPerMillion: 1.0, outputUsdPerMillion: 10.0);
    $unpriced = new ModelDefinition('m', inputUsdPerMillion: 1.0);

    // Reasoning tokens are already inside completion_tokens on OpenRouter.
    $usage = new Usage(promptTokens: 500_000, completionTokens: 100_000, reasoningTokens: 90_000);

    expect($priced->displayCostEstimateUsd($usage))->toEqualWithDelta(1.5, 0.0000001)
        ->and($unpriced->displayCostEstimateUsd($usage))->toBeNull();
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

    // The shipped catalog now declares both, and the app's first boot already
    // wrote them through — the provider deliberately never overwrites a value
    // that is already set, so clear them to observe this catalog's own boot.
    config()->set('ai.providers.openrouter.models.text.cheapest', null);
    config()->set('ai.providers.openrouter.models.text.smartest', null);

    rebootCatalog();

    expect(config('ai.providers.openrouter.models.text.cheapest'))->toBe('cheap/model')
        ->and(config('ai.providers.openrouter.models.text.smartest'))->toBe('smart/model');
});

it('serves the fleet default chat model, and lets an app override it', function () {
    // DECISIONS.md #26: the kit carries the shared default; apps inherit it
    // unless they say otherwise, so the published config's own value is what
    // a fresh app gets.
    $catalog = new Catalog(app(CatalogSource::class));

    expect($catalog->chatModel())->toBe('deepseek/deepseek-v4-flash-0731');

    config()->set('ai-kit.chat.model', 'google/gemini-3.1-flash-lite');

    expect($catalog->chatModel())->toBe('google/gemini-3.1-flash-lite');
});

/*
 * The shipped catalog is the fleet's shared registry (DECISIONS.md #26).
 * These guard the defaults themselves, not the machinery around them: the
 * whole point of #26 is that apps stop carrying their own copies, so a
 * regression here is a regression in every app at once.
 */

it('ships the ruled shared chat, vision, documents and authoring defaults', function () {
    $catalog = app(Catalog::class);

    expect($catalog->chatModel())->toBe('deepseek/deepseek-v4-flash-0731')
        ->and($catalog->chatReasoningEffort())->toBe('medium')
        ->and($catalog->visionModel())->toBe('google/gemini-3.1-flash-lite')
        ->and($catalog->visionFallbackModel())->toBe('google/gemini-3.5-flash-lite')
        ->and($catalog->documentsModel())->toBe('google/gemini-3.1-flash-lite')
        ->and($catalog->documentsFallbackModel())->toBe('google/gemini-3.5-flash-lite')
        ->and($catalog->authoringModel())->toBe('deepseek/deepseek-v4-pro-0813');
});

/*
 * DECISIONS.md #28a: every lane the fleet shares resolves to a model the
 * shipped catalog also DECLARES, so a lane inherits that entry's fallbacks and
 * price cap instead of routing at a slug nothing here describes. A lane that
 * drifts off the catalog is the failure this guards.
 */
it('resolves every shared lane to a declared catalog entry', function () {
    $catalog = app(Catalog::class);

    $lanes = [
        'chat' => $catalog->chatModel(),
        'vision' => $catalog->visionModel(),
        'vision fallback' => $catalog->visionFallbackModel(),
        'documents' => $catalog->documentsModel(),
        'documents fallback' => $catalog->documentsFallbackModel(),
        'authoring' => $catalog->authoringModel(),
    ];

    foreach ($lanes as $lane => $id) {
        expect($catalog->find($id))->not->toBeNull("the {$lane} lane routes at an undeclared model: {$id}");
    }
});

/*
 * The picker renders `label` and orders by `sort_order` (DECISIONS.md #28b),
 * so a row missing either is a row the user sees as a bare slug or in the
 * wrong place. `sort_order` must agree with price, because "sorted by price"
 * is the contract the ×-multiplier is read against.
 */
it('labels every shipped model and orders the chat menu by price', function () {
    $chat = app(Catalog::class)->forTask('chat')->values();

    foreach (app(Catalog::class)->models() as $model) {
        expect($model->label)->not->toBeNull("model {$model->id} ships no label")
            ->and($model->extra['sort_order'] ?? null)->not->toBeNull("model {$model->id} ships no sort_order");
    }

    $blended = $chat->map(fn (ModelDefinition $model): float => ($model->inputUsdPerMillion * 0.75) + ($model->outputUsdPerMillion * 0.25));

    expect($blended->all())->toBe($blended->sort()->values()->all());
});

it('keeps the chat default text-capable and the vision default vision-capable', function () {
    $catalog = app(Catalog::class);

    $chat = $catalog->find($catalog->chatModel());
    $vision = $catalog->find($catalog->visionModel());

    // The chat default is text-only on OpenRouter; routing an image at it
    // fails rather than degrades, which is why the two are separate keys.
    expect($chat)->not->toBeNull()
        ->and($chat->supports('vision'))->toBeFalse()
        ->and($chat->supports('tools'))->toBeTrue()
        ->and($chat->supports('reasoning'))->toBeTrue()
        ->and($vision)->not->toBeNull()
        ->and($vision->supports('vision'))->toBeTrue();
});

it('recommends exactly one shipped model per declared task', function () {
    $catalog = app(Catalog::class);

    $tasks = $catalog->models()
        ->flatMap(fn (ModelDefinition $model): array => $model->tasks)
        ->unique();

    expect($tasks)->not->toBeEmpty();

    foreach ($tasks as $task) {
        $recommended = $catalog->forTask($task)
            ->filter(fn (ModelDefinition $model): bool => $model->isRecommended());

        expect($recommended->count())
            ->toBe(1, "task '{$task}' must have exactly one recommended model");
    }

    expect($catalog->recommendedFor('chat')->id)->toBe('deepseek/deepseek-v4-flash-0731')
        ->and($catalog->recommendedFor('vision')->id)->toBe('google/gemini-3.1-flash-lite');
});

it('declares a fallback chain that resolves inside the shipped catalog', function () {
    $catalog = app(Catalog::class);
    $ids = $catalog->models()->map(fn (ModelDefinition $model): string => $model->id)->all();

    foreach ($catalog->models() as $model) {
        foreach ($model->fallbacks as $fallback) {
            expect(in_array($fallback, $ids, true))
                ->toBeTrue("{$model->id} falls back to undeclared {$fallback}");
        }
    }
});

it('preserves app-facing catalog keys the kit does not model itself', function () {
    // The shared catalog carries fields only the consuming app understands
    // (company, variant, tier, effort). Dropping them would be a silent
    // failure: the config reads correctly and the value never arrives.
    catalogConfig([
        'test/model' => [
            'label' => 'Test',
            'company' => 'DeepSeek',
            'variant' => 'fast',
            'effort' => 'medium',
            'extra' => ['already' => 'explicit'],
        ],
    ], ['replace_shipped_models' => true]);

    $model = app(CatalogSource::class)->find('test/model');

    expect($model->label)->toBe('Test')
        ->and($model->extra['company'])->toBe('DeepSeek')
        ->and($model->extra['variant'])->toBe('fast')
        ->and($model->extra['effort'])->toBe('medium')
        ->and($model->extra['already'])->toBe('explicit')
        ->and($model->extra)->not->toHaveKey('label');
});

it('carries company and variant through the shipped catalog', function () {
    $chat = app(Catalog::class)->find('deepseek/deepseek-v4-flash-0731');

    expect($chat->extra['company'])->toBe('DeepSeek')
        ->and($chat->extra['variant'])->toBe('fast');
});

it('gives every shipped model a unique, stable app key', function () {
    // Consuming apps store `key` against user selections and defaults
    // (catodemy's `assistant.default_model_key`), so a missing or duplicated
    // one silently breaks a picker rather than failing loudly here.
    $keys = app(Catalog::class)->models()
        ->map(fn (ModelDefinition $model): ?string => $model->extra['key'] ?? null)
        ->all();

    expect($keys)->not->toContain(null)
        ->and(array_unique($keys))->toHaveCount(count($keys));
});

it('prices every shipped model for display, including the cached-read rate', function () {
    foreach (app(Catalog::class)->models() as $model) {
        expect($model->inputUsdPerMillion)->toBeGreaterThan(0)
            ->and($model->outputUsdPerMillion)->toBeGreaterThan(0)
            ->and($model->extra['cache_read_usd_per_million'] ?? null)->not->toBeNull();
    }
});

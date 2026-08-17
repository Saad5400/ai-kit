<?php

use Saad\AiKit\Catalog\CatalogSource;
use Saad\AiKit\Catalog\ModelDefinition;
use Saad\AiKit\Testing\ArrayCatalogSource;

it('serves definitions built from config-shaped arrays keyed by model id', function () {
    $source = new ArrayCatalogSource([
        'test/model-a' => [
            'label' => 'Model A',
            'input_usd_per_million' => 0.3,
            'output_usd_per_million' => 2.5,
            'capabilities' => ['tools'],
            'fallbacks' => ['test/model-b'],
        ],
    ]);

    $model = $source->find('test/model-a');

    expect($model)->toBeInstanceOf(ModelDefinition::class)
        ->and($model->label)->toBe('Model A')
        ->and($model->fallbacks)->toBe(['test/model-b'])
        ->and($source->find('test/unknown'))->toBeNull()
        ->and($source->models())->toHaveCount(1);
});

it('accepts ready-made definitions and later additions, replacing by id', function () {
    $source = new ArrayCatalogSource([new ModelDefinition(id: 'test/model-a', label: 'First')]);

    $source->add(new ModelDefinition(id: 'test/model-a', label: 'Replaced'));
    $source->add(new ModelDefinition(id: 'test/model-b'));

    expect($source->models())->toHaveCount(2)
        ->and($source->find('test/model-a')?->label)->toBe('Replaced');
});

it('is bindable over the CatalogSource contract', function () {
    app()->instance(CatalogSource::class, new ArrayCatalogSource([new ModelDefinition(id: 'test/model-a')]));

    expect(app(CatalogSource::class)->find('test/model-a'))->not->toBeNull();
});

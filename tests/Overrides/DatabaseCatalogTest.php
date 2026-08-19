<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Saad\AiKit\Catalog\AiModel;
use Saad\AiKit\Catalog\Catalog;
use Saad\AiKit\Catalog\CatalogSource;
use Saad\AiKit\Catalog\DatabaseCatalogSource;
use Saad\AiKit\Tests\DatabaseCatalogTestCase;

uses(DatabaseCatalogTestCase::class, RefreshDatabase::class);

it('binds the database source and syncs the config catalog into the table', function () {
    expect(app(CatalogSource::class))->toBeInstanceOf(DatabaseCatalogSource::class);

    $this->artisan('ai-kit:sync-models')
        ->expectsOutputToContain('2 inserted, 0 updated, 0 disabled')
        ->assertSuccessful();

    $flash = app(CatalogSource::class)->find('test/flash');

    expect(AiModel::query()->count())->toBe(2)
        ->and($flash->label)->toBe('Flash')
        ->and($flash->tasks)->toBe(['chat', 'mcq'])
        ->and($flash->canonicalSlug)->toBe('test/flash-0731')
        ->and($flash->providerMaxPrice)->toBe(['prompt' => 0.5, 'completion' => 3.25])
        ->and($flash->extra['meta'])->toBe(['tier' => 'standard'])
        ->and(app(CatalogSource::class)->models()->pluck('id')->all())->toBe(['test/flash', 'test/lite']);
});

it('re-sync updates only dirty rows and never resurrects an ops-disabled row twice', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    config()->set('ai-kit.catalog.models.test/lite.label', 'Lite v2');

    $this->artisan('ai-kit:sync-models')
        ->expectsOutputToContain('0 inserted, 1 updated, 0 disabled')
        ->assertSuccessful();

    expect(AiModel::query()->where('key', 'test/lite')->value('label'))->toBe('Lite v2');
});

it('prunes by disabling, never deleting, and the source stops serving the row', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    config(['ai-kit.catalog.models' => [
        'test/flash' => config('ai-kit.catalog.models')['test/flash'],
    ]]);

    $this->artisan('ai-kit:sync-models --prune')
        ->expectsOutputToContain('0 inserted, 0 updated, 1 disabled')
        ->assertSuccessful();

    expect(AiModel::query()->count())->toBe(2)
        ->and(AiModel::query()->where('key', 'test/lite')->value('enabled'))->toBeFalse()
        ->and(app(CatalogSource::class)->find('test/lite'))->toBeNull()
        ->and(app(CatalogSource::class)->models())->toHaveCount(1);
});

it('refuses a catalog violating the recommended-per-task invariant, writing nothing', function () {
    config()->set('ai-kit.catalog.models.test/lite.tags', ['recommended']);

    $this->artisan('ai-kit:sync-models')->assertFailed();

    expect(AiModel::query()->count())->toBe(0);
});

it('ignores disabled entries when asserting the invariant', function () {
    config()->set('ai-kit.catalog.models.test/lite.tags', ['recommended']);
    config()->set('ai-kit.catalog.models.test/lite.enabled', false);

    $this->artisan('ai-kit:sync-models')->assertSuccessful();
});

it('keeps a stored canonical slug the config no longer declares', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    // A row synced before the column existed is the same case: the pin is in
    // the table and nowhere else, and a routine sync must not blank it.
    config()->set('ai-kit.catalog.models.test/flash.canonical_slug', null);

    $this->artisan('ai-kit:sync-models')
        ->expectsOutputToContain('0 inserted, 0 updated, 0 disabled')
        ->assertSuccessful();

    expect(AiModel::query()->where('key', 'test/flash')->value('canonical_slug'))->toBe('test/flash-0731');
});

it('re-pins a canonical slug the config declares differently', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    config()->set('ai-kit.catalog.models.test/flash.canonical_slug', 'test/flash-0902');

    $this->artisan('ai-kit:sync-models')
        ->expectsOutputToContain('0 inserted, 1 updated, 0 disabled')
        ->assertSuccessful();

    expect(AiModel::query()->where('key', 'test/flash')->value('canonical_slug'))->toBe('test/flash-0902');
});

it('finds a row by its canonical slug and hands back the routing id', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    expect(app(CatalogSource::class)->find('test/flash-0731')?->id)->toBe('test/flash');
});

it('routes tasks through the Catalog helper', function () {
    $this->artisan('ai-kit:sync-models')->assertSuccessful();

    $catalog = app(Catalog::class);

    expect($catalog->forTask('chat')->pluck('id')->all())->toBe(['test/flash', 'test/lite'])
        ->and($catalog->forTask('mcq')->pluck('id')->all())->toBe(['test/flash'])
        ->and($catalog->recommendedFor('chat')?->id)->toBe('test/flash')
        ->and($catalog->recommendedFor('summary'))->toBeNull();
});

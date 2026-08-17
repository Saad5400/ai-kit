<?php

use Saad\AiKit\Approvals\CachePlanStore;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\PlanStore;
use Saad\AiKit\Approvals\Plan;
use Saad\AiKit\Approvals\PlanBuilder;
use Saad\AiKit\Tests\Support\FakeProposableAction;

function storablePlan(): Plan
{
    $registry = app(ActionRegistry::class);
    $registry->register(new FakeProposableAction(type: 'update_widget'));

    return (new PlanBuilder($registry))
        ->summary('Rename a widget')
        ->scope(['widget_id' => 7])
        ->step('update_widget', 'Rename widget', ['widget_id' => 7, 'name' => 'B'], ['name: A → B'])
        ->build();
}

it('stores and fetches a plan for its owner, inputs intact', function () {
    $store = app(PlanStore::class);
    $plan = storablePlan();

    $store->put($plan, 'telegram:42');

    $fetched = $store->get($plan->id, 'telegram:42');

    expect($fetched)->not->toBeNull()
        ->and($fetched->toArray())->toBe($plan->toArray())
        ->and($fetched->steps[0]->input)->toBe(['widget_id' => 7, 'name' => 'B']);
});

it('verifies ownership on fetch', function () {
    $store = app(PlanStore::class);
    $plan = storablePlan();

    $store->put($plan, 'user:1');

    expect($store->get($plan->id, 'user:2'))->toBeNull()
        ->and($store->get('unknown-id', 'user:1'))->toBeNull();
});

it('expires plans after the configured TTL', function () {
    $store = new CachePlanStore(app('cache')->store(), ttlSeconds: 60);
    $plan = storablePlan();

    $store->put($plan, 'user:1');

    expect($store->get($plan->id, 'user:1'))->not->toBeNull();

    $this->travel(2)->minutes();

    expect($store->get($plan->id, 'user:1'))->toBeNull();
});

it('forgets a plan once its confirm turn ran', function () {
    $store = app(PlanStore::class);
    $plan = storablePlan();

    $store->put($plan, 'user:1');
    $store->forget($plan->id);

    expect($store->get($plan->id, 'user:1'))->toBeNull();
});

it('binds the store from config', function () {
    expect(app(PlanStore::class))->toBeInstanceOf(CachePlanStore::class);
});

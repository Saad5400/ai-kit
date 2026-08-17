<?php

use Saad\AiKit\Approvals\ArrayActionRegistry;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Tests\Support\DeferredProposableAction;
use Saad\AiKit\Tests\Support\FakeProposableAction;

it('resolves class-name registrations through the container on first lookup', function () {
    $registry = app(ActionRegistry::class);
    $registry->register(DeferredProposableAction::class);

    expect($registry->get('update_widget'))->toBeInstanceOf(DeferredProposableAction::class)
        // The same instance every time, so per-turn state on an action holds.
        ->and($registry->get('update_widget'))->toBe($registry->get('update_widget'))
        ->and($registry->all())->toHaveKey('update_widget');
});

it('lets the latest registration for a type win whichever form it took', function () {
    $registry = app(ActionRegistry::class);
    $instance = new FakeProposableAction(type: 'update_widget', category: 'instance');

    // A class name registered first is overridden by a later instance...
    $registry->register(DeferredProposableAction::class);
    $registry->register($instance);

    expect($registry->get('update_widget'))->toBe($instance);

    // ...and a class name registered last overrides an earlier instance.
    $registry->register(DeferredProposableAction::class);

    expect($registry->get('update_widget'))->toBeInstanceOf(DeferredProposableAction::class);
});

it('returns null for an unregistered type', function () {
    expect(app(ActionRegistry::class)->get('never_registered'))->toBeNull();
});

it('binds one registry instance behind both the contract and the concrete class', function () {
    expect(app(ActionRegistry::class))->toBe(app(ArrayActionRegistry::class));
});

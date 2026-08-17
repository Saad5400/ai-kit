<?php

use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\ProposalBag;
use Saad\AiKit\Tests\Support\FakeProposableAction;

function bagWith(FakeProposableAction ...$actions): ProposalBag
{
    app(ActionRegistry::class)->register(...$actions);

    return app(ProposalBag::class);
}

it('collects writes with server-derived risk flags', function () {
    $bag = bagWith(new FakeProposableAction(type: 'delete_widget', destructive: true, undoable: false));

    $write = $bag->propose('delete_widget', 'Delete widget 7', ['widget_id' => 7], ['Removes widget 7']);

    expect($bag->all())->toHaveCount(1)
        ->and($write->destructive)->toBeTrue()
        ->and($write->undoable)->toBeFalse()
        ->and($bag->isNotEmpty())->toBeTrue();
});

it('ignores an exact duplicate regardless of key order', function () {
    $bag = bagWith(new FakeProposableAction(type: 'update_widget'));

    $first = $bag->propose('update_widget', 'Rename', ['a' => 1, 'nested' => ['x' => 1, 'y' => 2]]);
    $second = $bag->propose('update_widget', 'Rename (retry)', ['nested' => ['y' => 2, 'x' => 1], 'a' => 1]);

    expect($bag->all())->toHaveCount(1)
        ->and($second)->toBe($first);
});

it('mints same-turn draft handles for creates and resolves their type', function () {
    $bag = bagWith(
        new FakeProposableAction(type: 'create_widget'),
        new FakeProposableAction(type: 'update_widget'),
    );

    $create = $bag->propose('create_widget', 'Create a widget', ['name' => 'A'], createsRecord: true);
    $update = $bag->propose('update_widget', 'Rename', ['widget_id' => 7]);
    $second = $bag->propose('create_widget', 'Create another', ['name' => 'B'], createsRecord: true);

    expect($create->draftRef)->toBe('new_create_widget_1')
        ->and($second->draftRef)->toBe('new_create_widget_2')
        ->and($update->draftRef)->toBeNull()
        ->and($bag->draftType('new_create_widget_1'))->toBe('create_widget')
        ->and($bag->draftType('unknown'))->toBeNull();
});

it('folds into a plan and flushes per turn', function () {
    $bag = bagWith(new FakeProposableAction(type: 'update_widget'));

    $bag->propose('update_widget', 'Rename widget', ['widget_id' => 7], ['name: A → B']);

    $plan = $bag->toPlan(summary: 'Rename it', scope: ['widget_id' => 7]);

    expect($plan->summary)->toBe('Rename it')
        ->and($plan->scope)->toBe(['widget_id' => 7])
        ->and($plan->steps)->toHaveCount(1)
        ->and($plan->steps[0]->title)->toBe('Rename widget')
        ->and($plan->autoApprove)->toBeTrue();

    $bag->flush();

    expect($bag->isEmpty())->toBeTrue();
});

it('is container-scoped so turns share one bag within a request', function () {
    expect(app(ProposalBag::class))->toBe(app(ProposalBag::class));

    app()->forgetScopedInstances();

    $bag = bagWith(new FakeProposableAction(type: 'update_widget'));
    $bag->propose('update_widget', 'Rename', []);

    app()->forgetScopedInstances();

    expect(app(ProposalBag::class)->isEmpty())->toBeTrue();
});

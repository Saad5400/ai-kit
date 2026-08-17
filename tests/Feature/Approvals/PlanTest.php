<?php

use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;
use Saad\AiKit\Approvals\Plan;
use Saad\AiKit\Approvals\PlanBuilder;
use Saad\AiKit\Approvals\ProposalStatus;
use Saad\AiKit\Approvals\ProposedWrite;
use Saad\AiKit\Tests\Support\FakeProposableAction;

afterEach(fn () => PlanBuilder::deriveAutoApproveUsing(null));

function planBuilder(FakeProposableAction ...$actions): PlanBuilder
{
    $registry = app(ActionRegistry::class);
    $registry->register(...$actions);

    return new PlanBuilder($registry);
}

it('derives destructive, undoable and typed_confirm from the registered action, never the caller', function () {
    $builder = planBuilder(
        new FakeProposableAction(type: 'delete_widget', destructive: true, undoable: false, typedConfirmPhrase: 'My Widget'),
        new FakeProposableAction(type: 'update_widget'),
    );

    $plan = $builder
        ->summary('Reshape widgets')
        ->step('delete_widget', 'Delete My Widget', ['widget_id' => 7], ['Removes 3 children'])
        ->step('update_widget', 'Rename a widget', ['widget_id' => 8])
        ->build();

    expect($plan->steps[0]->destructive)->toBeTrue()
        ->and($plan->steps[0]->undoable)->toBeFalse()
        ->and($plan->steps[0]->typedConfirm)->toBe('My Widget')
        ->and($plan->steps[1]->destructive)->toBeFalse()
        ->and($plan->destructive())->toBeTrue()
        ->and($plan->typedConfirm())->toBe('My Widget')
        ->and($plan->autoApprove)->toBeFalse();
});

it('refuses a step for an unregistered action type', function () {
    planBuilder()->step('ghost', 'Ghost step', []);
})->throws(UnknownActionException::class);

it('auto-approves exactly a single non-destructive undoable step', function () {
    $builder = fn () => planBuilder(
        new FakeProposableAction(type: 'update_widget'),
        new FakeProposableAction(type: 'delete_widget', destructive: true),
        new FakeProposableAction(type: 'send_email', undoable: false),
    );

    $single = $builder()->step('update_widget', 'Rename', [])->build();
    $double = $builder()->step('update_widget', 'Rename', ['a' => 1])->step('update_widget', 'Rename again', ['a' => 2])->build();
    $destructive = $builder()->step('delete_widget', 'Delete', [])->build();
    $irreversible = $builder()->step('send_email', 'Email everyone', [])->build();

    expect($single->autoApprove)->toBeTrue()
        ->and($double->autoApprove)->toBeFalse()
        ->and($destructive->autoApprove)->toBeFalse()
        ->and($irreversible->autoApprove)->toBeFalse();
});

it('never auto-approves when the config toggle is off', function () {
    config()->set('ai-kit.approvals.auto_approve', false);

    $plan = planBuilder(new FakeProposableAction(type: 'update_widget'))
        ->step('update_widget', 'Rename', [])
        ->build();

    expect($plan->autoApprove)->toBeFalse();
});

it('supports a custom auto-approve predicate', function () {
    PlanBuilder::deriveAutoApproveUsing(fn (array $steps, array $scope): bool => count($steps) <= 2
        && ! array_any($steps, fn (ProposedWrite $step): bool => $step->destructive));

    $plan = planBuilder(new FakeProposableAction(type: 'update_widget'))
        ->step('update_widget', 'Rename', ['a' => 1])
        ->step('update_widget', 'Rename again', ['a' => 2])
        ->build();

    expect($plan->autoApprove)->toBeTrue();
});

it('renders the canonical client payload and defaults the summary', function () {
    $plan = planBuilder(
        new FakeProposableAction(type: 'update_widget'),
        new FakeProposableAction(type: 'delete_widget', destructive: true, typedConfirmPhrase: 'Widget X'),
    )
        ->scope(['widget_id' => 7])
        ->step('update_widget', 'Rename widget', ['widget_id' => 7], ['name: A → B'])
        ->step('delete_widget', 'Delete Widget X', ['widget_id' => 7])
        ->build('plan-1');

    $payload = $plan->toClientPayload();

    expect($payload['id'])->toBe('plan-1')
        ->and($payload['summary'])->toBe('2 changes to apply')
        ->and($payload['scope'])->toBe(['widget_id' => 7])
        ->and($payload['destructive'])->toBeTrue()
        ->and($payload['auto_approve'])->toBeFalse()
        ->and($payload['status'])->toBe('pending')
        ->and($payload['typed_confirm'])->toBe('Widget X')
        ->and($payload['steps'][0])->toBe([
            'id' => $plan->steps[0]->id,
            'type' => 'update_widget',
            'title' => 'Rename widget',
            'preview' => ['name: A → B'],
            'destructive' => false,
        ])
        ->and($payload['steps'][1]['typed_confirm'])->toBe('Widget X')
        // Executable inputs never reach the client payload.
        ->and($payload['steps'][0])->not->toHaveKey('input');
});

it('round-trips through arrays with inputs intact', function () {
    $plan = planBuilder(new FakeProposableAction(type: 'update_widget'))
        ->summary('One change')
        ->scope(['widget_id' => 7])
        ->step('update_widget', 'Rename widget', ['widget_id' => 7, 'name' => 'B'], ['name: A → B'])
        ->build();

    $restored = Plan::fromArray($plan->toArray());

    expect($restored->toArray())->toBe($plan->toArray())
        ->and($restored->steps[0]->input)->toBe(['widget_id' => 7, 'name' => 'B'])
        ->and($restored->autoApprove)->toBeTrue();
});

it('carries status transitions immutably', function () {
    $plan = planBuilder(new FakeProposableAction(type: 'update_widget'))
        ->step('update_widget', 'Rename', [])
        ->build();

    $confirmed = $plan->withStatus(ProposalStatus::Confirmed);

    expect($plan->status)->toBe(ProposalStatus::Pending)
        ->and($confirmed->status)->toBe(ProposalStatus::Confirmed)
        ->and($confirmed->id)->toBe($plan->id);
});

<?php

use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\WriteRefusedException;
use Saad\AiKit\Approvals\Plan;
use Saad\AiKit\Approvals\PlanBuilder;
use Saad\AiKit\Approvals\WriteGate;
use Saad\AiKit\Approvals\WriteGateMode;
use Saad\AiKit\Tests\Support\FakeProposableAction;

function gateActions(): void
{
    app(ActionRegistry::class)->register(
        new FakeProposableAction(type: 'update_widget'),
        new FakeProposableAction(type: 'delete_widget', destructive: true),
        new FakeProposableAction(type: 'delete_gadget', destructive: true),
    );
}

function approvedPlan(array $stepTypes, array $scope = []): Plan
{
    $builder = (new PlanBuilder(app(ActionRegistry::class)))->scope($scope);

    foreach ($stepTypes as $type) {
        $builder->step($type, "Approved {$type}", $scope);
    }

    return $builder->build();
}

it('defaults to immediate mode and resolves modes at runtime only', function () {
    gateActions();
    $gate = app(WriteGate::class);

    expect($gate->mode())->toBe(WriteGateMode::Immediate)
        ->and($gate->inImmediateMode())->toBeTrue();

    $gate->enterPropose('turn-1');

    expect($gate->mode())->toBe(WriteGateMode::Propose)
        ->and($gate->turnId())->toBe('turn-1');

    $gate->enterExecute('turn-2', approvedPlan(['update_widget']));

    expect($gate->mode())->toBe(WriteGateMode::Execute)
        ->and($gate->turnId())->toBe('turn-2');

    $gate->reset();

    expect($gate->inImmediateMode())->toBeTrue()
        ->and($gate->turnId())->toBeNull();
});

it('collects proposed writes into the shared bag', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterPropose();

    $write = $gate->propose('update_widget', 'Rename widget', ['widget_id' => 7], ['name: A → B']);

    expect($gate->bag()->all())->toBe([$write])
        ->and($write->destructive)->toBeFalse();
});

it('does not guard outside execute mode', function () {
    gateActions();
    $gate = app(WriteGate::class);

    $write = $gate->propose('delete_widget', 'Delete widget', ['widget_id' => 7]);

    $gate->guard($write); // Immediate: no exception.
    $gate->enterPropose();
    $gate->guard($write); // Propose: no exception.

    expect(true)->toBeTrue();
});

it('refuses a destructive write that was not an approved plan step', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterExecute('turn-1', approvedPlan(['update_widget', 'delete_widget'], ['widget_id' => 7]));

    // The approved destructive type passes...
    $gate->guard($gate->propose('delete_widget', 'Delete widget 7', ['widget_id' => 7]));

    // ...a fresh destructive type is refused.
    $rogue = $gate->propose('delete_gadget', 'Delete gadget 3', ['widget_id' => 7]);

    try {
        $gate->guard($rogue);
        $this->fail('Expected WriteRefusedException.');
    } catch (WriteRefusedException $exception) {
        expect($exception->reason)->toBe(WriteRefusedException::REASON_OUT_OF_PLAN)
            ->and($exception->write)->toBe($rogue)
            ->and($exception->getMessage())->toContain('not one of the approved plan steps');
    }
});

it('refuses a write targeting an id outside the approved scope', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterExecute('turn-1', approvedPlan(['update_widget'], ['widget_id' => 7]));

    // In-scope, even nested, passes.
    $gate->guard($gate->propose('update_widget', 'Rename widget 7', ['items' => [['widget_id' => 7]]]));

    $rogue = $gate->propose('update_widget', 'Rename widget 9', ['items' => [['widget_id' => 9]]]);

    try {
        $gate->guard($rogue);
        $this->fail('Expected WriteRefusedException.');
    } catch (WriteRefusedException $exception) {
        expect($exception->reason)->toBe(WriteRefusedException::REASON_OUT_OF_SCOPE)
            ->and($exception->getMessage())->toContain("outside the approved plan's scope");
    }
});

it('treats a write naming none of the scoped keys as in-scope', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterExecute('turn-1', approvedPlan(['update_widget'], ['widget_id' => 7]));

    $gate->guard($gate->propose('update_widget', 'Touch something unmatched', ['other_id' => 9]));

    expect(true)->toBeTrue();
});

it('allows in-scope non-destructive deviation on purpose', function () {
    gateActions();
    $gate = app(WriteGate::class);
    // The approved plan contained only the delete; a small extra in-scope
    // non-destructive write may legitimately be needed mid-plan.
    $gate->enterExecute('turn-1', approvedPlan(['delete_widget'], ['widget_id' => 7]));

    $gate->guard($gate->propose('update_widget', 'Relink after delete', ['widget_id' => 7]));

    expect(true)->toBeTrue();
});

it('hands out monotonic per-turn sequences for the idempotency ledger', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterExecute('turn-1', approvedPlan(['update_widget']));

    expect($gate->nextSequence())->toBe(0)
        ->and($gate->nextSequence())->toBe(1);

    $gate->enterExecute('turn-2', approvedPlan(['update_widget']));

    expect($gate->nextSequence())->toBe(0);
});

it('is container-scoped so no mode leaks across turns', function () {
    gateActions();
    app(WriteGate::class)->enterExecute('turn-1', approvedPlan(['update_widget']));

    app()->forgetScopedInstances();

    expect(app(WriteGate::class)->inImmediateMode())->toBeTrue();
});

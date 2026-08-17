<?php

use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\WriteRefusedException;
use Saad\AiKit\Approvals\Plan;
use Saad\AiKit\Approvals\PlanBuilder;
use Saad\AiKit\Approvals\ProposedWrite;
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

it('refuses a destructive write targeting a record the approved step did not name', function () {
    gateActions();
    $gate = app(WriteGate::class);

    $plan = (new PlanBuilder(app(ActionRegistry::class)))
        ->step('delete_widget', 'Delete widget 7', ['widget_id' => 7])
        ->build();

    $gate->enterExecute('turn-1', $plan);

    $rogue = $gate->propose('delete_widget', 'Delete widget 8', ['widget_id' => 8]);

    try {
        $gate->guard($rogue);
        $this->fail('Expected WriteRefusedException.');
    } catch (WriteRefusedException $exception) {
        expect($exception->reason)->toBe(WriteRefusedException::REASON_OUT_OF_PLAN);
    }

    // The approved record itself still passes.
    $gate->guard($gate->propose('delete_widget', 'Delete widget 7', ['widget_id' => 7]));
});

it('consumes an approved destructive step so it authorizes exactly one delete', function () {
    gateActions();
    $gate = app(WriteGate::class);

    $plan = (new PlanBuilder(app(ActionRegistry::class)))
        ->step('delete_widget', 'Delete widget 7', ['widget_id' => 7])
        ->build();

    $gate->enterExecute('turn-1', $plan);

    $gate->guard(new ProposedWrite('w1', 'delete_widget', 'Delete widget 7', ['widget_id' => 7], destructive: true));

    $repeat = new ProposedWrite('w2', 'delete_widget', 'Delete widget 7 again', ['widget_id' => 7], destructive: true);

    expect(fn () => $gate->guard($repeat))->toThrow(WriteRefusedException::class, 'already carried out');
});

it('authorizes one delete per approved step when the plan approved several', function () {
    gateActions();
    $gate = app(WriteGate::class);

    $plan = (new PlanBuilder(app(ActionRegistry::class)))
        ->step('delete_widget', 'Delete widget 7', ['widget_id' => 7])
        ->step('delete_widget', 'Delete widget 9', ['widget_id' => 9])
        ->build();

    $gate->enterExecute('turn-1', $plan);

    $gate->guard(new ProposedWrite('w1', 'delete_widget', 'Delete 9', ['widget_id' => 9], destructive: true));
    $gate->guard(new ProposedWrite('w2', 'delete_widget', 'Delete 7', ['widget_id' => 7], destructive: true));

    $third = new ProposedWrite('w3', 'delete_widget', 'Delete 7 once more', ['widget_id' => 7], destructive: true);

    expect(fn () => $gate->guard($third))->toThrow(WriteRefusedException::class);
});

it('matches an approved step whose target was still a same-turn draft handle', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterPropose('turn-0');

    // The plan creates a widget and deletes the child it stood for; the id
    // only becomes real at execute time.
    $parent = $gate->propose('update_widget', 'Create widget', ['name' => 'A'], createsRecord: true);
    $gate->propose('delete_widget', 'Delete the new widget', ['widget_id' => $parent->draftRef]);

    $plan = $gate->bag()->toPlan('Create then delete');

    expect($parent->draftRef)->toBe('new_update_widget_1');

    $gate->enterExecute('turn-1', $plan);

    // The app resolved the handle to the persisted id before executing.
    $gate->guard(new ProposedWrite('w1', 'delete_widget', 'Delete the new widget', ['widget_id' => 4242], destructive: true));

    expect(true)->toBeTrue();
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

it('compares a scoped id of zero like any other id', function () {
    gateActions();
    $gate = app(WriteGate::class);
    $gate->enterExecute('turn-1', approvedPlan(['update_widget'], ['widget_id' => 7]));

    // "0" is a value, not an absent id — dropping it would walk straight
    // through the scope guard.
    $rogue = $gate->propose('update_widget', 'Rename widget 0', ['widget_id' => 0]);

    expect(fn () => $gate->guard($rogue))->toThrow(WriteRefusedException::class);
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

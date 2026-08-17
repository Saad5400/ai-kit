<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Exceptions\ActionValidationException;
use Saad\AiKit\Approvals\Exceptions\ProposalNotPendingException;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;
use Saad\AiKit\Approvals\Proposal;
use Saad\AiKit\Approvals\ProposalExecutor;
use Saad\AiKit\Approvals\ProposalStatus;
use Saad\AiKit\Tests\Support\FakeProposableAction;

uses(RefreshDatabase::class);

function registerAction(FakeProposableAction $action): ProposalExecutor
{
    app(ActionRegistry::class)->register($action);

    return app(ProposalExecutor::class);
}

it('proposes: validates, persists pending, and never executes', function () {
    $action = new FakeProposableAction(validateUsing: fn (array $input) => [...$input, 'normalized' => true]);
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', ['name' => 'A'], actor: 'actor-1', proposedBy: 'telegram:42');

    expect($proposal->isPending())->toBeTrue()
        ->and($proposal->type)->toBe('update_widget')
        ->and($proposal->category)->toBe('widgets')
        ->and($proposal->proposed_by)->toBe('telegram:42')
        ->and($proposal->payload)->toBe([
            'action' => 'update_widget',
            'category' => 'widgets',
            'input' => ['name' => 'A'],
            'preview' => ['name' => 'A', 'normalized' => true],
        ])
        ->and($action->executions)->toBe(0);
});

it('renders the exact 7-field flat client payload', function () {
    $executor = registerAction(new FakeProposableAction);

    $proposal = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');

    expect($proposal->toClientPayload())->toBe([
        'id' => $proposal->id,
        'type' => 'update_widget',
        'category' => 'widgets',
        'summary' => 'Summary of update_widget',
        'details' => ['name' => 'A'],
        'status' => 'pending',
        'error' => null,
    ]);
});

it('rejects proposing an unregistered type', function () {
    app(ProposalExecutor::class)->propose('nope', [], null, 'u:1');
})->throws(UnknownActionException::class);

it('lets a propose-time validation failure bubble to the tool', function () {
    $executor = registerAction((new FakeProposableAction)->failValidationWith('bad input'));

    $executor->propose('update_widget', [], null, 'u:1');
})->throws(ActionValidationException::class, 'bad input');

it('confirms: re-validates against current state and executes the stored input', function () {
    $action = new FakeProposableAction(validateUsing: fn (array $input) => [...$input, 'fresh' => true]);
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');
    expect($action->validations)->toBe(1);

    $confirmed = $executor->confirm($proposal, actor: 'actor-1');

    expect($confirmed->status)->toBe(ProposalStatus::Confirmed)
        ->and($confirmed->executed_at)->not->toBeNull()
        ->and($confirmed->error)->toBeNull()
        ->and($action->validations)->toBe(2)
        ->and($action->executions)->toBe(1)
        // Executes the freshly RE-normalized stored input — never model output.
        ->and($action->executedInputs[0])->toBe(['name' => 'A', 'fresh' => true]);
});

it('records the undoable flag through the undo ledger on confirm', function () {
    $spy = new class implements UndoLedger
    {
        public array $recorded = [];

        public function record(string $actionType, array $input, mixed $result, bool $undoable, ?string $turnId = null, ?int $sequence = null): void
        {
            $this->recorded[] = compact('actionType', 'undoable', 'result');
        }
    };

    app()->instance(UndoLedger::class, $spy);

    $executor = registerAction(new FakeProposableAction(undoable: false));
    $proposal = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');
    $executor->confirm($proposal, null);

    expect($spy->recorded)->toHaveCount(1)
        ->and($spy->recorded[0]['actionType'])->toBe('update_widget')
        ->and($spy->recorded[0]['undoable'])->toBeFalse()
        ->and($spy->recorded[0]['result'])->toBe(['applied' => true]);
});

it('marks the proposal failed when re-validation fails at confirm time', function () {
    $action = new FakeProposableAction;
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');

    // The state changed between propose and confirm.
    $action->failValidationWith('The widget no longer exists.');

    $failed = $executor->confirm($proposal, null);

    expect($failed->status)->toBe(ProposalStatus::Failed)
        ->and($failed->error)->toBe('The widget no longer exists.')
        ->and($failed->executed_at)->toBeNull()
        ->and($action->executions)->toBe(0)
        ->and($failed->toClientPayload()['status'])->toBe('failed');
});

it('marks the proposal failed when its type is no longer registered', function () {
    $executor = registerAction(new FakeProposableAction);
    $proposal = $executor->propose('update_widget', [], null, 'u:1');

    // Simulate the action being retired between deploys.
    $proposal->update(['type' => 'retired_action']);

    $failed = $executor->confirm($proposal->refresh(), null);

    expect($failed->status)->toBe(ProposalStatus::Failed)
        ->and($failed->error)->toContain('no longer supported');
});

it('masks unexpected execute failures behind a generic error', function () {
    $action = (new FakeProposableAction)->executeUsing(fn () => throw new RuntimeException('secret db detail'));
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', [], null, 'u:1');
    $failed = $executor->confirm($proposal, null);

    expect($failed->status)->toBe(ProposalStatus::Failed)
        ->and($failed->error)->not->toContain('secret')
        ->and($failed->error)->toContain('unexpected');
});

it('rejects a pending proposal without executing', function () {
    $action = new FakeProposableAction;
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', [], null, 'u:1');
    $rejected = $executor->reject($proposal);

    expect($rejected->status)->toBe(ProposalStatus::Rejected)
        ->and($action->executions)->toBe(0);
});

it('throws a typed 409-style exception when confirming a non-pending proposal', function () {
    $action = new FakeProposableAction;
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', [], null, 'u:1');
    $executor->confirm($proposal, null);

    expect($action->executions)->toBe(1);

    try {
        $executor->confirm($proposal->refresh(), null);
        $this->fail('Expected ProposalNotPendingException.');
    } catch (ProposalNotPendingException $exception) {
        // Carries the CURRENT proposal so the app's 409 body is the fresh card.
        expect($exception->proposal->is($proposal))->toBeTrue()
            ->and($exception->proposal->status)->toBe(ProposalStatus::Confirmed);
    }

    // The double confirm never re-executed.
    expect($action->executions)->toBe(1);
});

it('loses the atomic claim rather than double-executing a concurrently confirmed proposal', function () {
    $action = new FakeProposableAction;
    $executor = registerAction($action);

    $proposal = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');

    // The lost race: a parallel request confirmed and executed the proposal
    // after this one loaded the row, so the in-memory model still reads
    // pending and sails past the cheap guard.
    Proposal::query()->whereKey($proposal->id)->update(['status' => ProposalStatus::Confirmed->value]);

    expect($proposal->isPending())->toBeTrue();

    try {
        $executor->confirm($proposal, null);
        $this->fail('Expected ProposalNotPendingException.');
    } catch (ProposalNotPendingException $exception) {
        expect($exception->proposal->is($proposal))->toBeTrue()
            ->and($exception->proposal->status)->toBe(ProposalStatus::Confirmed);
    }

    // The claim refused before anything ran.
    expect($action->executions)->toBe(0)
        ->and($action->validations)->toBe(1)
        ->and(Proposal::query()->find($proposal->id)->status)->toBe(ProposalStatus::Confirmed);
});

it('loses the atomic claim rather than rejecting a concurrently confirmed proposal', function () {
    $executor = registerAction(new FakeProposableAction);

    $proposal = $executor->propose('update_widget', [], null, 'u:1');

    Proposal::query()->whereKey($proposal->id)->update(['status' => ProposalStatus::Confirmed->value]);

    try {
        $executor->reject($proposal);
        $this->fail('Expected ProposalNotPendingException.');
    } catch (ProposalNotPendingException $exception) {
        expect($exception->proposal->status)->toBe(ProposalStatus::Confirmed);
    }

    // The winner's outcome stands.
    expect(Proposal::query()->find($proposal->id)->status)->toBe(ProposalStatus::Confirmed);
});

it('throws the same typed exception when rejecting a non-pending proposal', function () {
    $executor = registerAction(new FakeProposableAction);

    $proposal = $executor->propose('update_widget', [], null, 'u:1');
    $executor->reject($proposal);

    $executor->reject($proposal->refresh());
})->throws(ProposalNotPendingException::class);

it('stores proposals in the configured table', function () {
    config()->set('ai-kit.approvals.proposals_table', 'ai_proposals');

    expect((new Proposal)->getTable())->toBe('ai_proposals');

    config()->set('ai-kit.approvals.proposals_table', 'custom_proposals');

    expect((new Proposal)->getTable())->toBe('custom_proposals');
});

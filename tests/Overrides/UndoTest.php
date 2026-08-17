<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Saad\AiKit\Approvals\Contracts\UndoLedger;
use Saad\AiKit\Approvals\Undo\CompensationApplier;
use Saad\AiKit\Approvals\Undo\DatabaseUndoLedger;
use Saad\AiKit\Approvals\Undo\UndoAction;
use Saad\AiKit\Approvals\Undo\UndoRecord;
use Saad\AiKit\Approvals\Undo\UndoTurn;
use Saad\AiKit\Tests\Support\UndoWidget;
use Saad\AiKit\Tests\UndoEnabledTestCase;

uses(UndoEnabledTestCase::class, RefreshDatabase::class);

beforeEach(function () {
    UndoWidget::$deleted = [];

    Schema::create('undo_widgets', function ($table) {
        $table->id();
        $table->string('name');
    });
});

function ledgeredRecord(array $overrides = []): UndoRecord
{
    return new UndoRecord(...array_replace([
        'actionType' => 'create_widget',
        'undoable' => true,
        'turnId' => 'turn-1',
        'sequence' => 0,
        'owner' => 'admin:1',
        'compensation' => ['op' => 'delete_models', 'model' => UndoWidget::class, 'ids' => [1]],
    ], $overrides));
}

it('binds the database ledger when undo is opted in', function () {
    expect(app(UndoLedger::class))->toBeInstanceOf(DatabaseUndoLedger::class);
});

it('ledgers records with a turn id and skips fire-and-forget ones', function () {
    $ledger = app(UndoLedger::class);

    $ledger->record(ledgeredRecord());
    $ledger->record(ledgeredRecord(['turnId' => null]));

    expect(UndoAction::query()->count())->toBe(1)
        ->and(UndoAction::query()->first()->owner)->toBe('admin:1');
});

it('replays a turn in reverse, one-shot, surfacing what could not roll back', function () {
    $first = UndoWidget::query()->create(['name' => 'قديم']);
    $second = UndoWidget::query()->create(['name' => 'ثاني']);

    $ledger = app(UndoLedger::class);

    // seq 0: update snapshot of $first; seq 1: created $second; seq 2: an email — not undoable.
    $ledger->record(ledgeredRecord([
        'actionType' => 'update_widget',
        'sequence' => 0,
        'compensation' => ['op' => 'restore_attributes', 'model' => UndoWidget::class,
            'records' => [['id' => $first->id, 'attributes' => ['name' => 'قديم']]]],
    ]));
    $first->update(['name' => 'معدل']);

    $ledger->record(ledgeredRecord(['sequence' => 1,
        'compensation' => ['op' => 'delete_models', 'model' => UndoWidget::class, 'ids' => [$second->id]]]));

    $ledger->record(ledgeredRecord([
        'actionType' => 'send_email', 'sequence' => 2, 'undoable' => false,
        'compensation' => null, 'notUndoableReason' => 'الرسالة أُرسلت بالفعل.',
    ]));

    // A foreign owner's row must not be touched.
    $ledger->record(ledgeredRecord(['owner' => 'admin:2', 'sequence' => 0]));

    $outcome = app(UndoTurn::class)->handle('turn-1', 'admin:1');

    expect($outcome['reverted'])->toBe(2)
        ->and($outcome['skipped'])->toBe([['action_type' => 'send_email', 'reason' => 'الرسالة أُرسلت بالفعل.']])
        ->and(UndoWidget::query()->find($first->id)->name)->toBe('قديم')
        ->and(UndoWidget::query()->find($second->id))->toBeNull()
        ->and(UndoWidget::$deleted)->toBe([$second->id])
        ->and(UndoAction::query()->where('owner', 'admin:1')->count())->toBe(0)
        ->and(UndoAction::query()->where('owner', 'admin:2')->count())->toBe(1);
});

it('replaying an already-undone turn reverts nothing (one-shot)', function () {
    app(UndoLedger::class)->record(ledgeredRecord());

    app(UndoTurn::class)->handle('turn-1', 'admin:1');
    $second = app(UndoTurn::class)->handle('turn-1', 'admin:1');

    expect($second)->toBe(['reverted' => 0, 'skipped' => []]);
});

it('applies custom ops via extend and ignores unknown ops', function () {
    $applier = new CompensationApplier;
    $applied = [];

    $applier->extend('detach_links', function (array $compensation) use (&$applied): void {
        $applied = $compensation['links'];
    });

    $applier->apply(['op' => 'detach_links', 'links' => [1, 2]]);
    $applier->apply(['op' => 'from_a_newer_version']);
    $applier->apply(['op' => 'delete_models', 'model' => 'NotAModel', 'ids' => [1]]);

    expect($applied)->toBe([1, 2]);
});

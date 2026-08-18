<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Approvals\Undo\UndoAction;
use Saad\AiKit\Approvals\WriteExecution;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\ClassifiedDeleteTool;
use Saad\AiKit\Tests\Support\ClassifiedLookupTool;
use Saad\AiKit\Tests\Support\ClassifiedRenameTool;
use Saad\AiKit\Tests\UndoEnabledTestCase;

uses(UndoEnabledTestCase::class, RefreshDatabase::class);

it('never gates reads and never ledgers them', function () {
    $tool = new ClassifiedLookupTool;

    expect($tool->shouldRequestApproval(new Request(['q' => 'x'], 'call-r')))->toBeNull()
        ->and((string) $tool->handle(new Request(['q' => 'x'], 'call-r')))->toBe('found it')
        ->and(WriteExecution::query()->count())->toBe(0)
        ->and(UndoAction::query()->count())->toBe(0);
});

it('auto-executes an undoable write into both ledgers, exactly once per tool call', function () {
    $tool = new ClassifiedRenameTool;
    $request = new Request(['name' => 'B'], 'call-1');

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $first = (string) $tool->handle($request);
    $second = (string) $tool->handle($request); // queue-retried resume replays

    expect($first)->toBe('renamed to B')
        ->and($second)->toBe('renamed to B')
        ->and($tool->performed)->toBe(1);

    $execution = WriteExecution::query()->sole();

    expect($execution->turn_id)->toBe('tool:call-1')
        ->and($execution->action_type)->toBe('ClassifiedRenameTool')
        ->and($execution->executed_by)->toBe('user:9')
        ->and($execution->undoable)->toBeTrue()
        ->and($execution->result['edited_by_user'])->toBeFalse();

    $undo = UndoAction::query()->sole();

    expect($undo->turn_id)->toBe('call-1')
        ->and($undo->compensation)->toBe(['op' => 'rename', 'name' => 'previous'])
        ->and($undo->undoable)->toBeTrue();
});

it('groups undo records under the usage turn when one is stamped', function () {
    Context::add(TurnContext::CURRENT_INVOCATION_KEY, 'inv-42');

    (new ClassifiedRenameTool)->handle(new Request(['name' => 'C'], 'call-2'));

    expect(UndoAction::query()->sole()->turn_id)->toBe('inv-42');
});

it('pauses a write that is not undoable, even with the ledger on', function () {
    $approval = (new ClassifiedRenameTool(undoable: false))
        ->shouldRequestApproval(new Request(['name' => 'B'], 'call-3'));

    expect($approval)->toBeInstanceOf(Approval::class);
});

it('always pauses destructive calls, with the server-derived reason', function () {
    $approval = (new ClassifiedDeleteTool)->shouldRequestApproval(new Request(['id' => 4], 'call-4'));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe('Permanently deletes the widget.');
});

it('honours the vendor per-instance overrides', function () {
    $tool = (new ClassifiedLookupTool)->requireApproval('operator said so');

    expect($tool->shouldRequestApproval(new Request([], 'call-5'))?->reason)->toBe('operator said so');

    $tool = (new ClassifiedDeleteTool)->withoutApproval();

    expect($tool->shouldRequestApproval(new Request([], 'call-6')))->toBeNull();
});

it('flags user-edited executions in the audit ledger', function () {
    ResumeDecisions::fromClient([
        'call-7' => ['action' => 'edit', 'arguments' => ['name' => 'user says D']],
    ]);

    (new ClassifiedRenameTool)->handle(new Request(['name' => 'user says D'], 'call-7'));

    expect(WriteExecution::query()->sole()->result['edited_by_user'])->toBeTrue();
});

it('executes fire-and-forget calls without ledgering when there is no tool-call id', function () {
    $output = (string) (new ClassifiedRenameTool)->handle(new Request(['name' => 'E']));

    expect($output)->toBe('renamed to E')
        ->and(WriteExecution::query()->count())->toBe(0)
        ->and(UndoAction::query()->count())->toBe(0); // null turn id = fire-and-forget
});

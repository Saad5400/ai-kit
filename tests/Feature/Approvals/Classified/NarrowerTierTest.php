<?php

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Tests\Support\ClassifiedLookupTool;
use Saad\AiKit\Tests\Support\ClassifiedRenameTool;

// Runs on the base TestCase: ai-kit.approvals.undo is OFF, so this covers
// the narrower auto-execute tier apps without undo get (owner decision #5).

it('pauses even undoable writes when the app runs no undo ledger', function () {
    expect((new ClassifiedRenameTool)->shouldRequestApproval(new Request(['name' => 'B'], 'call-1')))
        ->toBeInstanceOf(Approval::class);
});

it('still never gates reads', function () {
    expect((new ClassifiedLookupTool)->shouldRequestApproval(new Request(['q' => 'x'], 'call-2')))->toBeNull();
});

it('capability approval matrix matches the classified pause', function () {
    expect(Capability::read()->requiresApproval(true))->toBeFalse()
        ->and(Capability::read()->requiresApproval(false))->toBeFalse()
        ->and(Capability::write(undoable: true)->requiresApproval(true))->toBeFalse()
        ->and(Capability::write(undoable: true)->requiresApproval(false))->toBeTrue()
        ->and(Capability::write(undoable: false)->requiresApproval(true))->toBeTrue()
        ->and(Capability::destructive()->requiresApproval(true))->toBeTrue()
        ->and(Capability::destructive()->requiresApproval(false))->toBeTrue();
});

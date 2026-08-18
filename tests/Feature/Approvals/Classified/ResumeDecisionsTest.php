<?php

use Saad\AiKit\Approvals\Classified\ResumeDecisions;

it('maps the client decision shapes onto vendor decisions', function () {
    $decisions = ResumeDecisions::fromClient([
        'call-1' => true,
        'call-2' => 'approve',
        'call-3' => false,
        'call-4' => ['action' => 'reject', 'reason' => 'wrong record'],
        'call-5' => ['action' => 'edit', 'arguments' => ['name' => 'Corrected']],
    ]);

    expect($decisions->get('call-1')->isApproved())->toBeTrue()
        ->and($decisions->get('call-2')->isApproved())->toBeTrue()
        ->and($decisions->get('call-3')->isRejected())->toBeTrue()
        ->and($decisions->get('call-4')->result)->toBe('wrong record')
        ->and($decisions->get('call-5')->isEdited())->toBeTrue()
        ->and($decisions->get('call-5')->arguments)->toBe(['name' => 'Corrected']);
});

it('stamps edited ids for the execution audit, and only those', function () {
    ResumeDecisions::fromClient([
        'call-1' => true,
        'call-2' => ['action' => 'edit', 'arguments' => ['name' => 'B']],
    ]);

    expect(ResumeDecisions::wasEdited('call-2'))->toBeTrue()
        ->and(ResumeDecisions::wasEdited('call-1'))->toBeFalse();
});

it('rejects unknown actions and empty edits loudly', function () {
    expect(fn () => ResumeDecisions::fromClient(['call-1' => 'maybe']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResumeDecisions::fromClient(['call-1' => ['action' => 'edit']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResumeDecisions::fromClient(['call-1' => 3.14]))
        ->toThrow(InvalidArgumentException::class);
});

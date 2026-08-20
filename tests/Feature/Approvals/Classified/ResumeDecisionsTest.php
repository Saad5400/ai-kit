<?php

use Laravel\Ai\Approvals\PendingApproval;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Tests\Support\ClassifiedArticleTool;

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

it('guards edits into a plain payload a job can carry, restoring readonly fields', function () {
    $pending = new PendingApproval('call-1', 'ClassifiedArticleTool', [
        'article_id' => 5,
        'body' => 'original',
        'internal_note' => 'server-only',
    ], null);

    $guarded = ResumeDecisions::guarded([
        'call-1' => ['action' => 'edit', 'arguments' => [
            'article_id' => 999,
            'body' => 'edited by the user',
            'internal_note' => 'tampered',
        ]],
        'call-2' => true,
        'call-3' => ['action' => 'reject', 'reason' => 'wrong record'],
    ], (new ApprovalCards([new ClassifiedArticleTool]))->editGuard([$pending]));

    // The edit comes back reconciled against the server's pending call; the
    // decisions that carry no arguments come back exactly as they arrived.
    expect($guarded)->toBe([
        'call-1' => ['action' => 'edit', 'arguments' => [
            'article_id' => 5,
            'body' => 'edited by the user',
            'internal_note' => 'server-only',
        ]],
        'call-2' => true,
        'call-3' => ['action' => 'reject', 'reason' => 'wrong record'],
    ]);

    // What the resuming job then parses, with no guard of its own.
    expect(ResumeDecisions::fromClient($guarded)->get('call-1')->arguments)
        ->toBe(['article_id' => 5, 'body' => 'edited by the user', 'internal_note' => 'server-only']);
});

it('throws on an argument key the card never carried', function () {
    $pending = new PendingApproval('call-1', 'ClassifiedArticleTool', ['body' => 'original'], null);

    expect(fn () => ResumeDecisions::guarded(
        ['call-1' => ['action' => 'edit', 'arguments' => ['invented' => 'x']]],
        (new ApprovalCards([new ClassifiedArticleTool]))->editGuard([$pending]),
    ))->toThrow(InvalidArgumentException::class);
});

it('throws on an id that is not pending, and on a shape the resume could not read', function () {
    $guard = (new ApprovalCards([new ClassifiedArticleTool]))->editGuard([
        new PendingApproval('call-1', 'ClassifiedArticleTool', ['body' => 'original'], null),
    ]);

    expect(fn () => ResumeDecisions::guarded(['ghost' => ['action' => 'edit', 'arguments' => ['body' => 'x']]], $guard))
        ->toThrow(InvalidArgumentException::class)
        // The round-trip through fromClient catches this one — in the request
        // rather than inside the job.
        ->and(fn () => ResumeDecisions::guarded(['call-1' => 'maybe'], $guard))
        ->toThrow(InvalidArgumentException::class);
});

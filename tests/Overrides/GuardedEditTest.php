<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Approvals\WriteExecution;
use Saad\AiKit\Tests\Support\ClassifiedArticleTool;
use Saad\AiKit\Tests\UndoEnabledTestCase;

uses(UndoEnabledTestCase::class, RefreshDatabase::class);

it('does not let an edited readonly field reach the tool', function () {
    // The full resume path, one step at a time: the server's pending call,
    // the client's tampered edit, the guard, and the tool that finally runs
    // with whatever survived. `article_id` addresses the record the write
    // lands on — an edit to it would repoint the write at a record the user
    // was never shown a preview of.
    $tool = new ClassifiedArticleTool;

    $pending = new PendingApproval('call-a', 'ClassifiedArticleTool', [
        'article_id' => 5,
        'body' => 'original',
        'internal_note' => 'server-only',
    ], null);

    $decisions = ResumeDecisions::fromClient(
        ['call-a' => ['action' => 'edit', 'arguments' => [
            'article_id' => 999,
            'body' => 'edited by the user',
            'internal_note' => 'tampered',
        ]]],
        (new ApprovalCards([$tool]))->editGuard([$pending]),
    );

    // What the vendor loop hands the tool when it resumes an edited call.
    $tool->handle(new Request($decisions->get('call-a')->arguments, 'call-a'));

    expect($tool->received)->toBe([
        'article_id' => 5,
        'body' => 'edited by the user',
        'internal_note' => 'server-only',
    ]);
});

it('flags a guarded edit as user-edited in the execution ledger', function () {
    $tool = new ClassifiedArticleTool;
    $pending = new PendingApproval('call-b', 'ClassifiedArticleTool', ['body' => 'original'], null);

    $decisions = ResumeDecisions::fromClient(
        ['call-b' => ['action' => 'edit', 'arguments' => ['body' => 'mine']]],
        (new ApprovalCards([$tool]))->editGuard([$pending]),
    );

    $tool->handle(new Request($decisions->get('call-b')->arguments, 'call-b'));

    expect(WriteExecution::query()->sole()->result['edited_by_user'])->toBeTrue();
});

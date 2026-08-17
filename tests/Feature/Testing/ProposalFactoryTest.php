<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Saad\AiKit\Approvals\Proposal;
use Saad\AiKit\Approvals\ProposalStatus;

uses(RefreshDatabase::class);

it('creates a well-formed pending proposal by default', function () {
    $proposal = Proposal::factory()->create();

    expect($proposal->isPending())->toBeTrue()
        ->and($proposal->id)->not->toBeEmpty()
        ->and($proposal->payload)->toHaveKeys(['action', 'category', 'input', 'preview'])
        ->and($proposal->toClientPayload())->toHaveKeys([
            'id', 'type', 'category', 'summary', 'details', 'status', 'error',
        ]);
});

it('provides states for the non-pending outcomes and the owner key', function () {
    $confirmed = Proposal::factory()->confirmed()->proposedBy('telegram:42')->create();
    $rejected = Proposal::factory()->rejected()->create();
    $failed = Proposal::factory()->failed('boom')->create();

    expect($confirmed->status)->toBe(ProposalStatus::Confirmed)
        ->and($confirmed->executed_at)->not->toBeNull()
        ->and($confirmed->proposed_by)->toBe('telegram:42')
        ->and($rejected->status)->toBe(ProposalStatus::Rejected)
        ->and($failed->status)->toBe(ProposalStatus::Failed)
        ->and($failed->error)->toBe('boom');
});

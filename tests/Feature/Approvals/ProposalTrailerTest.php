<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\ProposalExecutor;
use Saad\AiKit\Approvals\ProposalStatus;
use Saad\AiKit\Approvals\ProposalTrailer;
use Saad\AiKit\Tests\Support\FakeProposableAction;

uses(RefreshDatabase::class);

it('renders the stable trailer and extracts it back', function () {
    $rendered = ProposalTrailer::render("A proposal awaits confirmation.\nSummary: rename it.", '01JXAMPLE');

    expect($rendered)->toBe("A proposal awaits confirmation.\nSummary: rename it.\n---\nproposal_id: 01JXAMPLE")
        ->and(ProposalTrailer::extract($rendered))->toBe('01JXAMPLE');
});

it('extracts only a line-anchored trailer', function () {
    expect(ProposalTrailer::extract('mentioning proposal_id: 01X inline'))->toBeNull()
        ->and(ProposalTrailer::extract("prefix proposal_id: 01X\n"))->toBeNull()
        ->and(ProposalTrailer::extract("text\nproposal_id: 01X\nmore"))->toBe('01X')
        ->and(ProposalTrailer::extract('no trailer at all'))->toBeNull();
});

it('extracts all trailers across tool results in order, ignoring non-strings', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return "done\n---\nproposal_id: 01B";
        }
    };

    $ids = ProposalTrailer::extractAll([
        ProposalTrailer::render('first', '01A'),
        'a plain result with no trailer',
        $stringable,
        ['not' => 'a string'],
        null,
    ]);

    expect($ids)->toBe(['01A', '01B']);
});

it('hydrates cards from the database with current statuses, skipping unknown ids', function () {
    $action = new FakeProposableAction;
    app(ActionRegistry::class)->register($action);
    $executor = app(ProposalExecutor::class);

    $first = $executor->propose('update_widget', ['name' => 'A'], null, 'u:1');
    $second = $executor->propose('update_widget', ['name' => 'B'], null, 'u:1');

    // The proposal was confirmed since the turn was stored — rehydration
    // must show the CURRENT status.
    $executor->confirm($second, null);

    $cards = ProposalTrailer::cards([
        ProposalTrailer::render('made one', $first),
        ProposalTrailer::render('made another', $second),
        ProposalTrailer::render('stale', '01JSTALEULIDNEVERPERSISTED'),
    ]);

    expect($cards)->toHaveCount(2)
        ->and($cards[0]['id'])->toBe($first->id)
        ->and($cards[0]['status'])->toBe(ProposalStatus::Pending->value)
        ->and($cards[1]['id'])->toBe($second->id)
        ->and($cards[1]['status'])->toBe(ProposalStatus::Confirmed->value)
        ->and($cards[0])->toHaveKeys(['id', 'type', 'category', 'summary', 'details', 'status', 'error']);
});

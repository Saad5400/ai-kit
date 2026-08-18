<?php

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\AskUser;

it('always pauses, carrying the question as the reason', function () {
    $approval = (new AskUser)->shouldRequestApproval(
        new Request(['question' => 'Which semester?'], 'call-q'),
    );

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe('Which semester?');
});

it('pauses with a null reason when the model sent no usable question', function () {
    $approval = (new AskUser)->shouldRequestApproval(new Request(['question' => '  '], 'call-q'));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBeNull();
});

it('returns the user answer to the model after an edit resume', function () {
    $output = (string) (new AskUser)->handle(
        new Request(['question' => 'Which semester?', 'answer' => 'Fall 2026'], 'call-q'),
    );

    expect($output)->toBe('The user answered: Fall 2026');
});

it('degrades gracefully when the user approved without answering', function () {
    $output = (string) (new AskUser)->handle(new Request(['question' => 'Which semester?'], 'call-q'));

    expect($output)->toContain('did not provide an answer');
});

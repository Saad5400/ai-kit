<?php

use Saad\AiKit\Testing\FakeTurnBuffer;

it('exercises the real buffer semantics on a private store', function () {
    $buffer = new FakeTurnBuffer;

    $buffer->start('turn-1', ['owner' => 'user:1']);
    $buffer->append('turn-1', 'delta', ['text' => 'مرحبا']);
    $buffer->finish('turn-1', ['conversation_id' => 'c-1']);

    $record = $buffer->get('turn-1');

    expect($record['status'])->toBe('done')
        ->and($record['meta']['owner'])->toBe('user:1')
        ->and($buffer->eventsAfter('turn-1', 1))->toHaveCount(1)
        ->and($buffer->claimPersist('turn-1'))->toBeTrue()
        ->and($buffer->claimPersist('turn-1'))->toBeFalse();
});

it('keeps each instance isolated from the next', function () {
    $first = new FakeTurnBuffer;
    $first->start('turn-1');

    expect((new FakeTurnBuffer)->get('turn-1'))->toBeNull();
});

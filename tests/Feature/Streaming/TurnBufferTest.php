<?php

use Saad\AiKit\Streaming\TurnBuffer;

beforeEach(function () {
    $this->buffer = $this->app->make(TurnBuffer::class);
});

it('starts an empty running log with the app meta', function () {
    $this->buffer->start('t1', ['user_id' => 7]);

    expect($this->buffer->get('t1'))->toBe([
        'status' => 'running',
        'cursor' => 0,
        'events' => [],
        'meta' => ['user_id' => 7],
    ]);
});

it('assigns monotonically increasing sequence numbers on append', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);
    $this->buffer->append('t1', 'delta', ['text' => 'b']);

    $turn = $this->buffer->get('t1');

    expect($turn['cursor'])->toBe(2)
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'a']],
            ['seq' => 2, 'event' => 'delta', 'data' => ['text' => 'b']],
        ]);
});

it('ignores appends to unknown or expired turns', function () {
    $this->buffer->append('ghost', 'delta', ['text' => 'a']);

    expect($this->buffer->get('ghost'))->toBeNull();
});

it('returns only the events after a cursor', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);
    $this->buffer->append('t1', 'delta', ['text' => 'b']);
    $this->buffer->append('t1', 'delta', ['text' => 'c']);

    expect($this->buffer->eventsAfter('t1', 2))->toBe([
        ['seq' => 3, 'event' => 'delta', 'data' => ['text' => 'c']],
    ])->and($this->buffer->eventsAfter('t1', 0))->toHaveCount(3)
        ->and($this->buffer->eventsAfter('t1', 3))->toBe([])
        ->and($this->buffer->eventsAfter('ghost', 0))->toBe([]);
});

it('finish appends the terminal done and folds meta into the record', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);
    $this->buffer->finish('t1', ['charged' => 2], ['conversation_id' => 'c9', 'message' => ['role' => 'assistant']]);

    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('done')
        ->and($turn['events'][1])->toBe(['seq' => 2, 'event' => 'done', 'data' => ['charged' => 2]])
        ->and($turn['meta'])->toBe(['conversation_id' => 'c9', 'message' => ['role' => 'assistant']]);
});

it('fail appends error then done and records the failure', function () {
    $this->buffer->start('t1');
    $this->buffer->fail('t1', 'something broke');

    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('failed')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'error', 'data' => ['message' => 'something broke']],
            ['seq' => 2, 'event' => 'done', 'data' => []],
        ])
        ->and($turn['meta']['error'])->toBe('something broke');
});

it('keeps cancellation on a separate key so it cannot race the producer appends', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);

    // The user cancels while the job is mid-turn...
    $this->buffer->cancel('t1');

    // ...and the job's in-flight append still lands untouched.
    $this->buffer->append('t1', 'delta', ['text' => 'b']);

    $turn = $this->buffer->get('t1');

    expect($this->buffer->isCancelled('t1'))->toBeTrue()
        ->and($turn['status'])->toBe('running')
        ->and($turn['events'])->toHaveCount(2);
});

it('is not cancelled by default', function () {
    $this->buffer->start('t1');

    expect($this->buffer->isCancelled('t1'))->toBeFalse();
});

it('grants the persist claim exactly once across concurrent tailers', function () {
    $this->buffer->start('t1');
    $this->buffer->finish('t1');

    expect($this->buffer->claimPersist('t1'))->toBeTrue()
        ->and($this->buffer->claimPersist('t1'))->toBeFalse();
});

it('forget clears the record, the persist claim and the cancel flag', function () {
    $this->buffer->start('t1');
    $this->buffer->cancel('t1');
    $this->buffer->claimPersist('t1');

    $this->buffer->forget('t1');

    expect($this->buffer->get('t1'))->toBeNull()
        ->and($this->buffer->isCancelled('t1'))->toBeFalse()
        ->and($this->buffer->claimPersist('t1'))->toBeTrue();
});

<?php

use Illuminate\Support\Carbon;
use Saad\AiKit\Testing\FakeTurnBuffer;

afterEach(fn () => Carbon::setTestNow());

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

it('mirrors status, exists, touch and upsert', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = new FakeTurnBuffer;

    expect($buffer->exists('turn-1'))->toBeFalse()
        ->and($buffer->status('turn-1'))->toBeNull();

    $buffer->start('turn-1');
    $buffer->upsert('turn-1', 'tool', ['id' => 'c1', 'progress' => 1]);
    $buffer->upsert('turn-1', 'tool', ['id' => 'c1', 'progress' => 2]);

    Carbon::setTestNow(Carbon::now()->addSeconds(10));
    $buffer->touch('turn-1');

    $record = $buffer->get('turn-1');

    expect($buffer->exists('turn-1'))->toBeTrue()
        ->and($buffer->status('turn-1'))->toBe('running')
        ->and($record['events'])->toBe([
            ['seq' => 2, 'event' => 'tool', 'data' => ['id' => 'c1', 'progress' => 2]],
        ])
        ->and($record['heartbeat_at'])->toBe(Carbon::now()->getTimestamp());
});

it('lets a test force page boundaries and the stale window', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = new FakeTurnBuffer(pageSize: 2, staleAfterSeconds: 5);

    $buffer->start('turn-1');
    foreach (range(1, 3) as $i) {
        $buffer->append('turn-1', 'delta', ['i' => $i]);
    }

    expect(array_column($buffer->eventsAfter('turn-1', 1), 'seq'))->toBe([2, 3]);

    Carbon::setTestNow(Carbon::now()->addSeconds(6));

    ob_start();
    $buffer->tail('turn-1', after: 3, maxSeconds: 0, staleMessage: 'stale');
    $out = ob_get_clean();

    expect($out)->toBe("id: 4\nevent: error\ndata: {\"message\":\"stale\"}\n\n")
        ->and($buffer->status('turn-1'))->toBe('failed');
});

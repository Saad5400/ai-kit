<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Saad\AiKit\Streaming\TurnBuffer;

beforeEach(function () {
    $this->buffer = $this->app->make(TurnBuffer::class);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * An array store that remembers every key it was asked to write, so a test
 * can prove an append touched the header and ONE page and nothing else.
 */
class RecordingArrayStore extends ArrayStore
{
    /** @var list<string> */
    public array $puts = [];

    public function put($key, $value, $seconds)
    {
        $this->puts[] = $key;

        return parent::put($key, $value, $seconds);
    }
}

it('starts an empty running log with the app meta', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');

    $this->buffer->start('t1', ['user_id' => 7]);

    $record = $this->buffer->get('t1');

    // The legacy keys keep their place and shape...
    expect(array_slice($record, 0, 4, true))->toBe([
        'status' => 'running',
        'cursor' => 0,
        'events' => [],
        'meta' => ['user_id' => 7],
    ])
        // ...and the header now carries liveness and layout.
        ->and($record['heartbeat_at'])->toBe(Carbon::now()->getTimestamp())
        ->and($record['started_at'])->toBe(Carbon::now()->getTimestamp())
        ->and($record['page_size'])->toBe(64);
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

it('fail ends the turn on error, with no done after it', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'partial']);
    $this->buffer->fail('t1', 'something broke', ['conversation_id' => 'c9']);

    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('failed')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'partial']],
            ['seq' => 2, 'event' => 'error', 'data' => ['message' => 'something broke']],
        ])
        ->and($turn['meta'])->toBe(['conversation_id' => 'c9', 'error' => 'something broke']);
});

it('appends a minimal done after error only when the app opts in', function () {
    $this->buffer->start('t1');
    $this->buffer->fail('t1', 'something broke', ['conversation_id' => 'c9'], trailingDone: true);

    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('failed')
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'error', 'data' => ['message' => 'something broke']],
            ['seq' => 2, 'event' => 'done', 'data' => []],
        ])
        ->and($turn['meta'])->toBe(['conversation_id' => 'c9', 'error' => 'something broke']);
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

// --- Paging ---------------------------------------------------------------

it('stores events in fixed-size pages under their own keys', function () {
    $buffer = new TurnBuffer(Cache::store('array'), pageSize: 3);

    $buffer->start('t1');
    foreach (range(1, 7) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    $header = Cache::store('array')->get('ai-kit:turn:t1');

    expect($header)->not->toHaveKey('events')
        ->and($header['cursor'])->toBe(7)
        ->and(array_column(Cache::store('array')->get('ai-kit:turn:t1:p0'), 'seq'))->toBe([1, 2, 3])
        ->and(array_column(Cache::store('array')->get('ai-kit:turn:t1:p1'), 'seq'))->toBe([4, 5, 6])
        ->and(array_column(Cache::store('array')->get('ai-kit:turn:t1:p2'), 'seq'))->toBe([7])
        ->and(Cache::store('array')->get('ai-kit:turn:t1:p3'))->toBeNull();
});

it('reads events exactly across page boundaries at every cursor', function () {
    $buffer = new TurnBuffer(Cache::store('array'), pageSize: 3);

    $buffer->start('t1');
    foreach (range(1, 200) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    foreach (range(0, 200) as $cursor) {
        expect(array_column($buffer->eventsAfter('t1', $cursor), 'seq'))
            ->toBe($cursor < 200 ? range($cursor + 1, 200) : []);
    }

    expect($buffer->get('t1')['events'])->toHaveCount(200)
        ->and($buffer->get('t1')['events'][199])->toBe(['seq' => 200, 'event' => 'delta', 'data' => ['i' => 200]]);
});

it('writes only the header and the current page on append', function () {
    $store = new RecordingArrayStore;
    $buffer = new TurnBuffer(new Repository($store), pageSize: 3);

    $buffer->start('t1');
    foreach (range(1, 7) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    $store->puts = [];
    $buffer->append('t1', 'delta', ['i' => 8]);

    // Seq 8 lands on page 2 — the older pages are never re-serialized.
    expect($store->puts)->toBe(['ai-kit:turn:t1:p2', 'ai-kit:turn:t1']);
});

it('start drops the pages left by an earlier run of the same turn id', function () {
    $buffer = new TurnBuffer(Cache::store('array'), pageSize: 2);

    $buffer->start('t1');
    foreach (range(1, 5) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    $buffer->start('t1');

    expect(Cache::store('array')->get('ai-kit:turn:t1:p0'))->toBeNull()
        ->and(Cache::store('array')->get('ai-kit:turn:t1:p2'))->toBeNull()
        ->and($buffer->get('t1')['events'])->toBe([]);
});

it('forget removes every page', function () {
    $buffer = new TurnBuffer(Cache::store('array'), pageSize: 2);

    $buffer->start('t1');
    foreach (range(1, 5) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    $buffer->forget('t1');

    expect(Cache::store('array')->get('ai-kit:turn:t1'))->toBeNull()
        ->and(Cache::store('array')->get('ai-kit:turn:t1:p0'))->toBeNull()
        ->and(Cache::store('array')->get('ai-kit:turn:t1:p1'))->toBeNull()
        ->and(Cache::store('array')->get('ai-kit:turn:t1:p2'))->toBeNull();
});

// --- TTL, heartbeat, cheap reads ------------------------------------------

it('slides the TTL on every append and on touch', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = new TurnBuffer(Cache::store('array'), ttlSeconds: 10);

    $buffer->start('t1');
    $buffer->start('idle');

    Carbon::setTestNow(Carbon::now()->addSeconds(6));
    $buffer->append('t1', 'delta', ['text' => 'a']);

    Carbon::setTestNow(Carbon::now()->addSeconds(6));
    $buffer->touch('t1');

    Carbon::setTestNow(Carbon::now()->addSeconds(3));

    // 15s after start: the written-to turn lives (header touched 3s ago,
    // page written 9s ago), the idle one expired at 10s.
    expect($buffer->exists('t1'))->toBeTrue()
        ->and($buffer->eventsAfter('t1', 0))->toHaveCount(1)
        ->and($buffer->exists('idle'))->toBeFalse();
});

it('keeps the whole log alive for a full TTL after the turn finishes', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $buffer = new TurnBuffer(Cache::store('array'), ttlSeconds: 10, pageSize: 2);

    $buffer->start('t1');
    $buffer->append('t1', 'delta', ['text' => 'a']);
    $buffer->append('t1', 'delta', ['text' => 'b']);

    // Page 0 is full and will not be written again by appends...
    Carbon::setTestNow(Carbon::now()->addSeconds(6));
    $buffer->append('t1', 'delta', ['text' => 'c']);
    $buffer->finish('t1');

    // ...but finishing re-puts it, so a resume 12s after start still
    // replays the first page.
    Carbon::setTestNow(Carbon::now()->addSeconds(6));

    expect(array_column($buffer->eventsAfter('t1', 0), 'seq'))->toBe([1, 2, 3, 4]);
});

it('touch refreshes the heartbeat without appending an event', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $this->buffer->start('t1');
    $started = Carbon::now()->getTimestamp();

    Carbon::setTestNow(Carbon::now()->addSeconds(42));
    $this->buffer->touch('t1');

    $record = $this->buffer->get('t1');

    expect($record['heartbeat_at'])->toBe($started + 42)
        ->and($record['started_at'])->toBe($started)
        ->and($record['cursor'])->toBe(0)
        ->and($record['events'])->toBe([]);
});

it('append stamps the heartbeat', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $this->buffer->start('t1');

    Carbon::setTestNow(Carbon::now()->addSeconds(30));
    $this->buffer->append('t1', 'delta', ['text' => 'a']);

    expect($this->buffer->get('t1')['heartbeat_at'])->toBe(Carbon::now()->getTimestamp());
});

it('answers status and existence from the header alone', function () {
    $store = new RecordingArrayStore;
    $buffer = new TurnBuffer(new Repository($store), pageSize: 2);

    $buffer->start('t1');
    foreach (range(1, 5) as $i) {
        $buffer->append('t1', 'delta', ['i' => $i]);
    }

    expect($buffer->status('t1'))->toBe('running')
        ->and($buffer->exists('t1'))->toBeTrue()
        ->and($buffer->status('ghost'))->toBeNull()
        ->and($buffer->exists('ghost'))->toBeFalse();

    $buffer->finish('t1');

    expect($buffer->status('t1'))->toBe('done');
});

// --- Append guard ---------------------------------------------------------

it('refuses appends once the turn has finished', function () {
    $this->buffer->start('t1');
    $this->buffer->finish('t1', ['ok' => true]);

    $this->buffer->append('t1', 'delta', ['text' => 'late']);
    $this->buffer->touch('t1');

    $turn = $this->buffer->get('t1');

    expect($turn['cursor'])->toBe(1)
        ->and(array_column($turn['events'], 'event'))->toBe(['done']);
});

it('refuses appends and a late finish once the turn has failed', function () {
    $this->buffer->start('t1');
    $this->buffer->fail('t1', 'stale', ['stale' => true]);

    // A producer that wakes up after the tail declared the turn dead.
    $this->buffer->append('t1', 'delta', ['text' => 'late']);
    $this->buffer->finish('t1', ['ok' => true], ['conversation_id' => 'c9']);

    $turn = $this->buffer->get('t1');

    expect($turn['status'])->toBe('failed')
        ->and(array_column($turn['events'], 'event'))->toBe(['error'])
        ->and($turn['meta'])->toBe(['stale' => true, 'error' => 'stale']);
});

// --- Upsert ---------------------------------------------------------------

it('upsert replaces the tail entry about the same subject with a fresh seq', function () {
    $this->buffer->start('t1');
    $this->buffer->append('t1', 'delta', ['text' => 'a']);
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 10]);
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 20]);
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 30]);

    $turn = $this->buffer->get('t1');

    expect($turn['cursor'])->toBe(4)
        ->and($turn['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'a']],
            ['seq' => 4, 'event' => 'tool', 'data' => ['id' => 'call-1', 'progress' => 30]],
        ])
        // A client that last saw seq 2 still receives the latest state.
        ->and(array_column($this->buffer->eventsAfter('t1', 2), 'seq'))->toBe([4]);
});

it('upsert appends when the tail entry is a different event or subject, or lacks the key', function () {
    $this->buffer->start('t1');
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 10]);
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-2', 'progress' => 10]);
    $this->buffer->upsert('t1', 'delta', ['id' => 'call-2']);
    $this->buffer->upsert('t1', 'delta', ['text' => 'no key']);
    $this->buffer->upsert('t1', 'delta', ['text' => 'no key either']);

    expect(array_column($this->buffer->get('t1')['events'], 'seq'))->toBe([1, 2, 3, 4, 5]);
});

it('upsert only ever rewrites the tail — an earlier entry about the same subject stays', function () {
    $this->buffer->start('t1');
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 10]);
    $this->buffer->append('t1', 'delta', ['text' => 'between']);
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 20]);

    expect(array_column($this->buffer->get('t1')['events'], 'seq'))->toBe([1, 2, 3]);
});

it('upsert honours a custom subject key', function () {
    $this->buffer->start('t1');
    $this->buffer->upsert('t1', 'progress', ['job' => 'j1', 'done' => 1], key: 'job');
    $this->buffer->upsert('t1', 'progress', ['job' => 'j1', 'done' => 2], key: 'job');

    expect($this->buffer->get('t1')['events'])->toBe([
        ['seq' => 2, 'event' => 'progress', 'data' => ['job' => 'j1', 'done' => 2]],
    ]);
});

it('upsert keeps seqs ascending when the replacement rolls onto a new page', function () {
    $buffer = new TurnBuffer(Cache::store('array'), pageSize: 2);

    $buffer->start('t1');
    $buffer->append('t1', 'delta', ['text' => 'a']);
    $buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 10]);   // seq 2, page 0 full
    $buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 20]);   // seq 3 → page 1
    $buffer->upsert('t1', 'tool', ['id' => 'call-1', 'progress' => 30]);   // seq 4 → page 1

    expect(array_column(Cache::store('array')->get('ai-kit:turn:t1:p0'), 'seq'))->toBe([1])
        ->and(array_column(Cache::store('array')->get('ai-kit:turn:t1:p1'), 'seq'))->toBe([4])
        ->and($buffer->get('t1')['events'])->toBe([
            ['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'a']],
            ['seq' => 4, 'event' => 'tool', 'data' => ['id' => 'call-1', 'progress' => 30]],
        ])
        ->and(array_column($buffer->eventsAfter('t1', 1), 'seq'))->toBe([4]);

    $buffer->append('t1', 'delta', ['text' => 'b']);

    expect(array_column($buffer->eventsAfter('t1', 0), 'seq'))->toBe([1, 4, 5]);
});

it('upsert is a no-op once the turn is terminal', function () {
    $this->buffer->start('t1');
    $this->buffer->finish('t1');
    $this->buffer->upsert('t1', 'tool', ['id' => 'call-1']);

    expect($this->buffer->get('t1')['cursor'])->toBe(1);
});

// --- Records written before the header/pages split ------------------------

it('still reads, appends to and upserts a legacy inline record', function () {
    Cache::store('array')->put('ai-kit:turn:old', [
        'status' => 'running',
        'cursor' => 1,
        'events' => [['seq' => 1, 'event' => 'delta', 'data' => ['text' => 'a']]],
        'meta' => ['user_id' => 7],
    ], 60);

    $this->buffer->append('old', 'delta', ['text' => 'b']);
    $this->buffer->upsert('old', 'tool', ['id' => 'c1', 'progress' => 1]);
    $this->buffer->upsert('old', 'tool', ['id' => 'c1', 'progress' => 2]);

    expect($this->buffer->status('old'))->toBe('running')
        ->and(array_column($this->buffer->eventsAfter('old', 1), 'seq'))->toBe([2, 4])
        ->and($this->buffer->get('old')['events'])->toHaveCount(3)
        ->and(Cache::store('array')->get('ai-kit:turn:old:p0'))->toBeNull();

    $this->buffer->finish('old');

    expect($this->buffer->status('old'))->toBe('done')
        ->and($this->buffer->get('old')['cursor'])->toBe(5);
});

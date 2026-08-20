<?php

use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Streaming\ToolProgress;
use Saad\AiKit\Tests\Support\LongTurnBuffer;

beforeEach(function () {
    ToolProgress::unbind();

    Carbon::setTestNow(Carbon::now());

    $this->events = [];
    $this->sink = function (string $event, array $data): void {
        $this->events[] = [$event, $data];
    };
});

afterEach(function () {
    ToolProgress::unbind();
});

it('is a harmless no-op when nothing is bound', function () {
    $progress = ToolProgress::current();

    $progress->report('id1', label: 'working');

    $seen = [];
    $progress->for('id1')->each([1, 2, 3], function (int $item) use (&$seen): void {
        $seen[] = $item;
    });

    expect($progress->isCancelled())->toBeFalse()
        ->and($seen)->toBe([1, 2, 3]);
});

it('emits a running tool frame with progress and no name', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->report('id1', label: 'classifying', current: 1, total: 40);

    expect($this->events)->toBe([
        ['tool', [
            'id' => 'id1',
            'status' => 'running',
            'progress' => ['label' => 'classifying', 'current' => 1, 'total' => 40],
        ]],
    ]);
});

it('throttles repeat reports per call id, touching the heartbeat instead', function () {
    $buffer = new LongTurnBuffer;
    $buffer->start('t1');

    ToolProgress::bind('t1', $this->sink, $buffer);

    ToolProgress::current()->report('id1', label: 'working', current: 1, total: 10);
    ToolProgress::current()->report('id1', label: 'working', current: 2, total: 10);

    expect($this->events)->toHaveCount(1)
        ->and($buffer->touches)->toBe(1);

    Carbon::setTestNow(Carbon::now()->addMilliseconds(1100));

    ToolProgress::current()->report('id1', label: 'working', current: 3, total: 10);

    expect($this->events)->toHaveCount(2)
        ->and($this->events[1][1]['progress']['current'])->toBe(3);
});

it('lets a label change through the throttle immediately', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->report('id1', label: 'downloading');
    ToolProgress::current()->report('id1', label: 'extracting');

    expect($this->events)->toHaveCount(2)
        ->and($this->events[1][1]['progress']['label'])->toBe('extracting');
});

it('lets the final state through the throttle immediately', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->report('id1', label: 'working', current: 1, total: 3);
    ToolProgress::current()->report('id1', label: 'working', current: 2, total: 3);
    ToolProgress::current()->report('id1', label: 'working', current: 3, total: 3);

    expect($this->events)->toHaveCount(2)
        ->and($this->events[1][1]['progress'])->toBe(['label' => 'working', 'current' => 3, 'total' => 3]);
});

it('treats percent >= 100 as final too, clamped to 100', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->report('id1', percent: 10.0);
    ToolProgress::current()->report('id1', percent: 120.0);

    expect($this->events)->toHaveCount(2)
        ->and($this->events[1][1]['progress'])->toBe(['percent' => 100.0]);
});

it('throttles call ids independently', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->report('id1', label: 'a');
    ToolProgress::current()->report('id2', label: 'a');

    expect($this->events)->toHaveCount(2);
});

it('pins a reporter to the tool call id of a laravel/ai Request', function () {
    ToolProgress::bind('t1', $this->sink);

    ToolProgress::current()->for(new Request(['q' => 'x'], 'call-9'))->report(label: 'searching');

    expect($this->events)->toBe([
        ['tool', ['id' => 'call-9', 'status' => 'running', 'progress' => ['label' => 'searching']]],
    ]);
});

it('reports nothing for a Request without a call id but still iterates', function () {
    ToolProgress::bind('t1', $this->sink);

    $seen = [];
    ToolProgress::current()->for(new Request(['q' => 'x']))->each(['a', 'b'], function (string $item) use (&$seen): void {
        $seen[] = $item;
    });

    expect($this->events)->toBe([])
        ->and($seen)->toBe(['a', 'b']);
});

it('each() reports current/total over a countable, with the final state never throttled away', function () {
    ToolProgress::bind('t1', $this->sink);

    $seen = [];
    ToolProgress::current()->for('id1')->each(['a', 'b', 'c'], function (string $item) use (&$seen): void {
        $seen[] = $item;
    }, label: 'classifying');

    // Frozen clock: the first report (0/3) and the final one (3/3) pass the
    // throttle; the intermediate ones are eaten by it.
    expect($seen)->toBe(['a', 'b', 'c'])
        ->and($this->events)->toBe([
            ['tool', ['id' => 'id1', 'status' => 'running', 'progress' => ['label' => 'classifying', 'current' => 0, 'total' => 3]]],
            ['tool', ['id' => 'id1', 'status' => 'running', 'progress' => ['label' => 'classifying', 'current' => 3, 'total' => 3]]],
        ]);
});

it('each() reports a generator without a total until the end, where the total is known', function () {
    ToolProgress::bind('t1', $this->sink);

    $items = (function (): Generator {
        yield 'a';
        yield 'b';
    })();

    ToolProgress::current()->for('id1')->each($items, fn () => null, label: 'importing');

    expect($this->events)->toBe([
        ['tool', ['id' => 'id1', 'status' => 'running', 'progress' => ['label' => 'importing', 'current' => 1]]],
        ['tool', ['id' => 'id1', 'status' => 'running', 'progress' => ['label' => 'importing', 'current' => 2, 'total' => 2]]],
    ]);
});

it('each() stops iterating when the turn is cancelled, without throwing', function () {
    $cancelled = false;

    ToolProgress::bind('t1', $this->sink, null, function () use (&$cancelled): bool {
        return $cancelled;
    });

    $seen = [];
    ToolProgress::current()->for('id1')->each([1, 2, 3, 4], function (int $item) use (&$seen, &$cancelled): void {
        $seen[] = $item;

        if ($item === 2) {
            $cancelled = true;

            // The cancel probe is throttled to one read per second; move
            // past the window so the next item's check actually probes.
            Carbon::setTestNow(Carbon::now()->addMilliseconds(1100));
        }
    });

    expect($seen)->toBe([1, 2]);
});

it('isCancelled is throttled, sticky, and defaults to the buffer cancel flag', function () {
    $buffer = new LongTurnBuffer;
    $buffer->start('t1');

    ToolProgress::bind('t1', $this->sink, $buffer);

    $progress = ToolProgress::current();

    expect($progress->isCancelled())->toBeFalse();

    // The stop lands, but the last probe was under a second ago.
    $buffer->cancel('t1');

    expect($progress->isCancelled())->toBeFalse();

    Carbon::setTestNow(Carbon::now()->addMilliseconds(1100));

    expect($progress->isCancelled())->toBeTrue()
        // Sticky: no further probes needed once seen.
        ->and($progress->isCancelled())->toBeTrue();
});

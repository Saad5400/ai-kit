<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saad\AiKit\Approvals\WriteExecution;
use Saad\AiKit\Approvals\WriteExecutions;

uses(RefreshDatabase::class);

it('claims a (turn, sequence) row inside the app transaction', function () {
    $ledger = new WriteExecutions;

    DB::transaction(function () use ($ledger) {
        // ... the app's own Eloquent write would happen here ...
        $ledger->claim('turn-1', 0, 'update_widget', 'user:1', ['ids' => [7]], undoable: true);
    });

    $row = $ledger->find('turn-1', 0);

    expect($row)->not->toBeNull()
        ->and($row->action_type)->toBe('update_widget')
        ->and($row->executed_by)->toBe('user:1')
        ->and($row->result)->toBe(['ids' => [7]])
        ->and($row->undoable)->toBeTrue();
});

it('enforces exactly-once via the unique key', function () {
    $ledger = new WriteExecutions;

    $ledger->claim('turn-1', 0, 'update_widget');

    try {
        $ledger->claim('turn-1', 0, 'update_widget');
        $this->fail('Expected a unique-key violation.');
    } catch (QueryException $exception) {
        expect(WriteExecutions::isUniqueViolation($exception))->toBeTrue();
    }

    // A different step of the same turn is fine.
    $ledger->claim('turn-1', 1, 'update_widget');

    expect(WriteExecution::query()->count())->toBe(2);
});

it('replays a double-executed step without re-running the write', function () {
    $ledger = new WriteExecutions;
    $runs = 0;

    $write = function () use (&$runs) {
        $runs++;

        return ['ids' => [7], 'message' => 'Renamed.'];
    };

    $first = $ledger->execute('turn-1', 0, 'update_widget', 'user:1', $write);
    $second = $ledger->execute('turn-1', 0, 'update_widget', 'user:1', $write);

    expect($runs)->toBe(1)
        ->and($first['replayed'])->toBeFalse()
        ->and($second['replayed'])->toBeTrue()
        ->and($second['result'])->toBe(['ids' => [7], 'message' => 'Renamed.'])
        ->and($second['execution']->is($first['execution']))->toBeTrue();
});

it('falls back to the winner when losing a concurrent claim race', function () {
    // Simulate the winner's commit landing between our replay check and our
    // claim: the first look sees nothing, the claim then hits the unique key.
    $ledger = new class extends WriteExecutions
    {
        public int $finds = 0;

        public function find(string $turnId, int $sequence): ?WriteExecution
        {
            return $this->finds++ === 0 ? null : parent::find($turnId, $sequence);
        }
    };

    // The concurrent re-dispatch already committed this step.
    (new WriteExecutions)->claim('turn-1', 0, 'update_widget', 'user:2', ['ids' => [99]]);

    $runs = 0;

    $result = $ledger->execute('turn-1', 0, 'update_widget', 'user:1', function () use (&$runs) {
        $runs++;

        return ['ids' => [1]];
    });

    // Our write ran but rolled back with the failed claim — the winner's
    // committed result is what comes back.
    expect($runs)->toBe(1)
        ->and($result['replayed'])->toBeTrue()
        ->and($result['result'])->toBe(['ids' => [99]])
        ->and(WriteExecution::query()->count())->toBe(1);
});

it('does not run the write a second time after a failure was rolled back', function () {
    $ledger = new WriteExecutions;

    try {
        $ledger->execute('turn-1', 0, 'update_widget', null, fn () => throw new RuntimeException('boom'));
        $this->fail('Expected the write failure to bubble.');
    } catch (RuntimeException) {
        // The failed step left no ledger row — a retry may run it again.
    }

    expect(WriteExecution::query()->count())->toBe(0);

    $retry = $ledger->execute('turn-1', 0, 'update_widget', null, fn () => ['ids' => [7]]);

    expect($retry['replayed'])->toBeFalse();
});

it('uses the configured table name', function () {
    config()->set('ai-kit.approvals.write_executions_table', 'custom_executions');

    expect((new WriteExecution)->getTable())->toBe('custom_executions');
});

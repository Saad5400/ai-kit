<?php

use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Storage\StoredMessage;
use Saad\AiKit\Conversations\TurnState;
use Saad\AiKit\Streaming\TurnCancelledException;

it('maps completed and paused straight through, from the enum or the column string', function () {
    expect(TurnState::of(MessageStatus::Completed, ['provider' => 'openrouter']))->toBe(TurnState::Completed)
        ->and(TurnState::of('completed'))->toBe(TurnState::Completed)
        ->and(TurnState::of('paused', '[]'))->toBe(TurnState::Paused)
        ->and(TurnState::of(MessageStatus::Paused))->toBe(TurnState::Paused);
});

it('tells a stopped turn from a failed one by meta.error', function () {
    expect(TurnState::of('failed', ['error' => 'Provider returned error']))->toBe(TurnState::Failed)
        ->and(TurnState::of('failed', ['error' => TurnCancelledException::MESSAGE]))->toBe(TurnState::Stopped)
        ->and(TurnState::of('failed', []))->toBe(TurnState::Failed)
        ->and(TurnState::of('failed'))->toBe(TurnState::Failed);
});

it('reads a raw meta column, sealed or plaintext', function () {
    $stopped = json_encode(['provider' => 'openrouter', 'error' => TurnCancelledException::MESSAGE]);

    expect(TurnState::of('failed', Crypt::encryptString($stopped)))->toBe(TurnState::Stopped)
        ->and(TurnState::of('failed', $stopped))->toBe(TurnState::Stopped)
        ->and(TurnState::of('failed', Crypt::encryptString('{"error":"boom"}')))->toBe(TurnState::Failed)
        ->and(TurnState::of('failed', 'not json'))->toBe(TurnState::Failed)
        // Traces off keeps only the error, still sealed.
        ->and(TurnState::of('failed', Crypt::encryptString(json_encode(['error' => TurnCancelledException::MESSAGE]))))->toBe(TurnState::Stopped);
});

it('classifies a StoredMessage the store handed out', function () {
    $message = new StoredMessage(id: 'm1', role: 'assistant', content: 'Saving it.', meta: ['error' => TurnCancelledException::MESSAGE], status: MessageStatus::Failed);

    expect(TurnState::ofMessage($message))->toBe(TurnState::Stopped)
        ->and(TurnState::ofMessage($message)->value)->toBe('stopped')
        ->and(TurnState::ofMessage($message)->interrupted())->toBeTrue()
        ->and(TurnState::Completed->interrupted())->toBeFalse()
        ->and(TurnState::Paused->interrupted())->toBeFalse()
        ->and(TurnState::Failed->interrupted())->toBeTrue();
});

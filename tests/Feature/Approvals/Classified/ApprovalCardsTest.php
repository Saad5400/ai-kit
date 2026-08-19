<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\AskUser;
use Saad\AiKit\Streaming\StreamEventMapper;
use Saad\AiKit\Tests\Support\ClassifiedDeleteTool;
use Saad\AiKit\Tests\Support\ClassifiedRenameTool;

class UnclassifiedLegacyTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'A tool that never declared its capability.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        return 'done';
    }
}

function classifiedCards(): ApprovalCards
{
    return new ApprovalCards([
        new ClassifiedRenameTool,
        new ClassifiedDeleteTool,
        new AskUser,
        new UnclassifiedLegacyTool,
    ]);
}

it('renders a payload write as an editable form card, classification server-derived', function () {
    $card = classifiedCards()->card(
        new PendingApproval('call-1', 'ClassifiedRenameTool', ['name' => 'B'], null),
    );

    expect($card['kind'])->toBe('approval')
        ->and($card['destructive'])->toBeFalse()
        ->and($card['undoable'])->toBeTrue()
        ->and($card['editable'])->toBeTrue()
        ->and($card['title'])->toBe('Classified Rename Tool')
        ->and($card['arguments'])->toBe(['name' => 'B']);
});

it('renders destructive calls as one-click cards — no editable form', function () {
    $card = classifiedCards()->card(
        new PendingApproval('call-2', 'ClassifiedDeleteTool', ['id' => 4], 'Permanently deletes the widget.'),
    );

    expect($card['destructive'])->toBeTrue()
        ->and($card['editable'])->toBeFalse()
        ->and($card['reason'])->toBe('Permanently deletes the widget.');
});

it('fails safe: an unclassified tool renders as destructive', function () {
    $card = classifiedCards()->card(
        new PendingApproval('call-3', 'UnclassifiedLegacyTool', [], null),
    );

    expect($card['destructive'])->toBeTrue()
        ->and($card['editable'])->toBeFalse();
});

it('renders AskUser pauses as question cards', function () {
    $card = classifiedCards()->card(
        new PendingApproval('call-4', 'AskUser', ['question' => 'Which semester?'], 'Which semester?'),
    );

    expect($card)->toBe([
        'kind' => 'question',
        'id' => 'call-4',
        'question' => 'Which semester?',
    ]);
});

it('carries the model suggested answers on a question card, and omits the key otherwise', function () {
    $withOptions = classifiedCards()->card(new PendingApproval('call-5', 'AskUser', [
        'question' => 'Which semester?',
        'options' => ['Fall 2026', 'Spring 2027'],
    ], null));

    expect($withOptions['options'])->toBe(['Fall 2026', 'Spring 2027'])
        ->and(classifiedCards()->card(new PendingApproval('call-6', 'AskUser', ['question' => 'Why?'], null)))
        ->not->toHaveKey('options');
});

it('emits one wire event per card through the stream mapper, then done', function () {
    $pause = new ToolApprovalRequest('evt-1', collect([
        new PendingApproval('call-1', 'ClassifiedRenameTool', ['name' => 'B'], null),
        new PendingApproval('call-4', 'AskUser', ['question' => 'Which semester?'], 'Which semester?'),
    ]), timestamp: 123);

    $emitted = [];

    classifiedCards()
        ->attachTo(new StreamEventMapper)
        ->run([$pause], function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

    expect(array_column($emitted, 0))->toBe(['approval', 'question', 'done'])
        ->and($emitted[0][1]['tool'])->toBe('ClassifiedRenameTool')
        ->and($emitted[1][1]['question'])->toBe('Which semester?');
});

it('shares one id between a paused tool call and its approval card', function () {
    // The provider yields the ToolCall before the loop decides the call
    // needs approval, so the client gets a `tool` chip and then a card for
    // the same call. They carry the same id — vendor PendingApproval is
    // built from `$toolCall->id` — which is what lets the client fold the
    // running chip into the card instead of stranding a spinner, and lets
    // the resumed turn's `tool done` land on the right chip.
    $call = new ToolCall('evt-0', new ToolCallData('call-1', 'ClassifiedRenameTool', ['name' => 'B']), 1);

    $pause = new ToolApprovalRequest('evt-1', collect([
        new PendingApproval('call-1', 'ClassifiedRenameTool', ['name' => 'B'], null),
    ]), timestamp: 123);

    $emitted = [];

    classifiedCards()
        ->attachTo(new StreamEventMapper)
        ->run([$call, $pause], function (string $event, array $data) use (&$emitted): void {
            $emitted[] = [$event, $data];
        });

    expect(array_column($emitted, 0))->toBe(['tool', 'approval', 'done'])
        ->and($emitted[0][1])->toBe(['id' => 'call-1', 'name' => 'ClassifiedRenameTool', 'status' => 'running'])
        ->and($emitted[1][1]['id'])->toBe('call-1');
});

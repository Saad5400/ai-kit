<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Approvals\Classified\StoredApprovals;
use Saad\AiKit\Conversations\EncryptedConversationStore;

uses(RefreshDatabase::class);

function storedApprovalsPrompt(): AgentPrompt
{
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test agent';
        }
    };

    return new AgentPrompt($agent, 'do the thing', [], app(AiManager::class)->textProvider('openrouter'), 'test/model');
}

function storedApprovalsPause(array $calls, array $pending): AgentResponse
{
    $response = new AgentResponse(
        (string) Str::uuid7(),
        '',
        new Usage(promptTokens: 10, completionTokens: 5),
        new Meta('openrouter', 'test/model'),
    );

    $response->withToolCallsAndResults(collect($calls), collect([]));
    $response->withPendingApprovals(collect($pending));

    return $response;
}

it('reconstructs pending approvals from an encrypted paused row and drops resolved ones', function () {
    $store = new EncryptedConversationStore;

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'Paused chat');

    $store->storeAssistantMessage($conversationId, 'App\\Models\\User', '7', storedApprovalsPrompt(), storedApprovalsPause(
        [
            new ToolCall('call-a', 'DeleteWidget', ['id' => 4]),
            new ToolCall('call-b', 'RenameWidget', ['id' => 4, 'name' => 'new']),
        ],
        [
            new PendingApproval('call-a', 'DeleteWidget', ['id' => 4], 'destructive'),
            new PendingApproval('call-b', 'RenameWidget', ['id' => 4, 'name' => 'new']),
        ],
    ));

    $pending = (new StoredApprovals)->pending($conversationId);

    expect($pending)->toHaveCount(2)
        ->and($pending[0]->id)->toBe('call-a')
        ->and($pending[0]->tool)->toBe('DeleteWidget')
        ->and($pending[0]->arguments)->toBe(['id' => 4])
        ->and($pending[0]->reason)->toBe('destructive')
        ->and($pending[1]->id)->toBe('call-b')
        ->and($pending[1]->reason)->toBeNull();

    $store->storeApprovalResults($conversationId, 'App\\Models\\User', '7', [
        new ToolResult('call-a', 'DeleteWidget', ['id' => 4], 'deleted'),
    ]);

    $remaining = (new StoredApprovals)->pending($conversationId);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->id)->toBe('call-b');
});

it('returns nothing for a conversation without a pause', function () {
    $store = new EncryptedConversationStore;

    $conversationId = $store->storeConversation('App\\Models\\User', '7', 'Plain chat');
    $store->storeUserMessage($conversationId, 'App\\Models\\User', '7', storedApprovalsPrompt());

    expect((new StoredApprovals)->pending($conversationId))->toBeEmpty();
});

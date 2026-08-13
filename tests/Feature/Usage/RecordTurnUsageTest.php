<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Saad\AiKit\Gateway\SpendCollector;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Usage\Events\TurnUsageRecorded;
use Saad\AiKit\Usage\TurnSpend;
use Saad\AiKit\Usage\UsageEvent;

uses(RefreshDatabase::class);

function usageAgent(): Agent
{
    return new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'test agent';
        }
    };
}

/**
 * @return array{0: AgentPrompted, 1: AgentResponse}
 */
function promptedEvent(bool $streamed = false, ?Usage $usage = null, ?string $invocationId = null): array
{
    $invocationId ??= (string) Str::uuid7();
    $agent = usageAgent();

    $prompt = new AgentPrompt(
        $agent,
        'hello',
        [],
        app(AiManager::class)->textProvider('openrouter'),
        'test/model',
        invocationId: $invocationId,
    );

    $response = new AgentResponse(
        $invocationId,
        'response text',
        $usage ?? new Usage(promptTokens: 100, completionTokens: 25, reasoningTokens: 5),
        new Meta(provider: 'openrouter', model: 'test/model'),
    );

    $event = $streamed
        ? new AgentStreamed($invocationId, $prompt, $response)
        : new AgentPrompted($invocationId, $prompt, $response);

    return [$event, $response];
}

it('records a usage row when an agent turn completes', function () {
    Event::fake([TurnUsageRecorded::class]);

    [$event] = promptedEvent();

    event($event);

    $row = UsageEvent::sole();

    expect($row->invocation_id)->toBe($event->invocationId)
        ->and($row->provider)->toBe('openrouter')
        ->and($row->model)->toBe('test/model')
        ->and($row->streamed)->toBeFalse()
        ->and($row->prompt_tokens)->toBe(100)
        ->and($row->completion_tokens)->toBe(25)
        ->and($row->reasoning_tokens)->toBe(5)
        ->and($row->status)->toBe('ok')
        ->and($row->cost_usd)->toBeNull()
        ->and($row->cost_source)->toBeNull();

    Event::assertDispatched(TurnUsageRecorded::class, fn (TurnUsageRecorded $e) => $e->usage->is($row));
});

it('marks streamed turns as streamed', function () {
    [$event] = promptedEvent(streamed: true);

    event($event);

    expect(UsageEvent::sole()->streamed)->toBeTrue();
});

it('records the collector cost and generation ids, draining by default', function () {
    $collector = app(SpendCollector::class);
    $collector->recordCost(0.002, true);
    $collector->recordCost(0.001, false);
    $collector->recordGenerationId('gen-1', true);

    [$event] = promptedEvent(streamed: true);

    event($event);

    $row = UsageEvent::sole();

    expect($row->cost_usd)->toEqualWithDelta(0.003, 0.0000001)
        ->and($row->cost_source)->toBe('provider')
        ->and($row->generation_ids)->toBe(['gen-1'])
        ->and($collector->totalCost())->toBe(0.0);
});

it('leaves the collector intact when drain_spend is off', function () {
    config()->set('ai-kit.usage.drain_spend', false);

    $collector = app(SpendCollector::class);
    $collector->recordCost(0.002, true);

    [$event] = promptedEvent(streamed: true);

    event($event);

    expect(UsageEvent::sole()->cost_usd)->toEqualWithDelta(0.002, 0.0000001)
        ->and($collector->totalCost())->toEqualWithDelta(0.002, 0.0000001);
});

it('estimates cost from catalog prices when the provider reported none', function () {
    config()->set('ai-kit.catalog.models', [
        'test/model' => ['input_usd_per_million' => 1.0, 'output_usd_per_million' => 10.0],
    ]);

    [$event] = promptedEvent(usage: new Usage(promptTokens: 1_000_000, completionTokens: 100_000));

    event($event);

    $row = UsageEvent::sole();

    expect($row->cost_usd)->toEqualWithDelta(2.0, 0.0000001)
        ->and($row->cost_source)->toBe('estimated');
});

it('records duration and ttft from the turn context and clears the stamps', function () {
    [$event] = promptedEvent();

    event(new PromptingAgent($event->invocationId, $event->prompt));
    Context::add(TurnContext::TTFT_KEY, 123);

    event($event);

    $row = UsageEvent::sole();

    expect($row->duration_ms)->not->toBeNull()
        ->and($row->ttft_ms)->toBe(123)
        ->and(Context::get(TurnContext::startedAtKey($event->invocationId)))->toBeNull()
        ->and(Context::get(TurnContext::TTFT_KEY))->toBeNull()
        ->and(Context::get(TurnContext::CURRENT_INVOCATION_KEY))->toBeNull();
});

it('records conversation and participant when present', function () {
    [$event, $response] = promptedEvent();

    $participant = new class
    {
        public string $id = 'session-abc';
    };

    $response->withinConversation('11111111-1111-1111-1111-111111111111', $participant);

    event($event);

    $row = UsageEvent::sole();

    expect($row->conversation_id)->toBe('11111111-1111-1111-1111-111111111111')
        ->and($row->participant_type)->toBe($participant::class)
        ->and($row->participant_id)->toBe('session-abc');
});

it('labels the turn from the feature context key', function () {
    Context::add('ai-kit.feature', 'assistant');

    [$event] = promptedEvent();

    event($event);

    expect(UsageEvent::sole()->feature)->toBe('assistant');
});

it('records a failed_over row per abandoned attempt', function () {
    Context::add(TurnContext::CURRENT_INVOCATION_KEY, $invocationId = (string) Str::uuid7());

    event(new AgentFailedOver(
        usageAgent(),
        app(AiManager::class)->textProvider('openrouter'),
        'test/model',
        RateLimitedException::forProvider('openrouter'),
    ));

    $row = UsageEvent::sole();

    expect($row->status)->toBe('failed_over')
        ->and($row->invocation_id)->toBe($invocationId)
        ->and($row->model)->toBe('test/model')
        ->and($row->error)->toContain('RateLimitedException')
        ->and($row->cost_usd)->toBeNull();
});

it('never breaks the turn when recording fails', function () {
    Schema::drop('ai_usage_events');

    [$event] = promptedEvent();

    event($event);

    expect(true)->toBeTrue();
});

it('sums spend for reconciliation via TurnSpend', function () {
    UsageEvent::create(['invocation_id' => Str::uuid7(), 'provider' => 'openrouter', 'model' => 'm', 'feature' => 'assistant', 'cost_usd' => 2.0, 'created_at' => now()]);
    UsageEvent::create(['invocation_id' => Str::uuid7(), 'provider' => 'openrouter', 'model' => 'm', 'feature' => 'telegram', 'cost_usd' => 1.5, 'created_at' => now()]);
    UsageEvent::create(['invocation_id' => Str::uuid7(), 'provider' => 'openrouter', 'model' => 'm', 'cost_usd' => 9.0, 'created_at' => now()->subDays(2)]);

    $spend = app(TurnSpend::class);

    expect($spend->todayUsd())->toEqualWithDelta(3.5, 0.000001)
        ->and($spend->todayUsd('assistant'))->toEqualWithDelta(2.0, 0.000001);
});

<?php

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Saad\AiKit\Catalog\ConfigCatalogSource;
use Saad\AiKit\Catalog\ModelRouting;
use Saad\AiKit\Tests\Support\GatewayFactory;

function stepBody(array $config, bool $finalStep, array $tools, ?ModelRouting $routing = null): array
{
    return GatewayFactory::buildStepBody(
        GatewayFactory::gateway($config, routing: $routing),
        GatewayFactory::provider(),
        'test/model',
        null,
        [new UserMessage('question')],
        $tools,
        null,
        null,
        new StepContext(stepNumber: $finalStep ? 5 : 1, isFinalStep: $finalStep),
    );
}

/** Routing over a one-entry config catalog declared inline. */
function routingFor(array $definition): ModelRouting
{
    config()->set('ai-kit.catalog.models', ['test/model' => $definition]);

    return new ModelRouting(new ConfigCatalogSource(config()));
}

it('withholds tools on the final step and injects the answer-now nudge', function () {
    $body = stepBody([], finalStep: true, tools: [GatewayFactory::fakeTool()]);

    expect($body)->not->toHaveKeys(['tools', 'tool_choice']);

    $lastMessage = end($body['messages']);

    expect($lastMessage['role'])->toBe('user')
        ->and($lastMessage['content'])->toContain('Tool steps are over');
});

it('keeps tools on non-final steps', function () {
    $body = stepBody([], finalStep: false, tools: [GatewayFactory::fakeTool()]);

    expect($body['tools'])->toHaveCount(1)
        ->and($body['tool_choice'])->toBe('auto')
        ->and(end($body['messages'])['content'])->toBe('question');
});

it('can disable final-step withholding', function () {
    $body = stepBody(['final_step' => ['withhold_tools' => false]], finalStep: true, tools: [GatewayFactory::fakeTool()]);

    expect($body['tools'])->toHaveCount(1);
});

it('withholds without a nudge when the message is emptied', function () {
    $body = stepBody(['final_step' => ['message' => '']], finalStep: true, tools: [GatewayFactory::fakeTool()]);

    expect($body)->not->toHaveKey('tools')
        ->and(end($body['messages'])['content'])->toBe('question');
});

// OpenRouter returns full usage on every response now, so the `usage:
// {include: true}` the gateway used to force was buying nothing. The inline
// cost/generation-id extraction is unchanged.
it('no longer asks for usage accounting', function () {
    expect(stepBody([], false, []))->not->toHaveKey('usage');
});

it('sends the declared chain as the models array, itself first', function () {
    $body = stepBody([], false, [], routingFor(['fallbacks' => ['backup/one', 'backup/two']]));

    expect($body['models'])->toBe(['test/model', 'backup/one', 'backup/two']);
});

it('sends nothing when the model declares no chain and no cap', function () {
    $body = stepBody([], false, [], routingFor(['label' => 'Plain']));

    expect($body)->not->toHaveKeys(['models', 'provider']);
});

it('sends a declared price cap as OpenRouter provider routing', function () {
    $body = stepBody([], false, [], routingFor(['provider_max_price' => ['prompt' => 0.5, 'completion' => 3.25]]));

    expect($body['provider'])->toBe(['max_price' => ['prompt' => 0.5, 'completion' => 3.25]]);
});

it('leaves the body bare when no catalog is wired in', function () {
    expect(stepBody([], false, []))->not->toHaveKeys(['models', 'provider']);
});

it('asks OpenRouter for upstreams that support every parameter on tool steps only', function () {
    $withTools = stepBody([], finalStep: false, tools: [GatewayFactory::fakeTool()]);
    $withoutTools = stepBody([], finalStep: false, tools: []);
    $finalStep = stepBody([], finalStep: true, tools: [GatewayFactory::fakeTool()]);
    $disabled = stepBody(['require_parameters' => false], finalStep: false, tools: [GatewayFactory::fakeTool()]);

    expect($withTools['provider'])->toBe(['require_parameters' => true])
        ->and($withoutTools)->not->toHaveKey('provider')
        ->and($finalStep)->not->toHaveKey('provider')
        ->and($disabled)->not->toHaveKey('provider');
});

it('merges require_parameters with the catalog price cap rather than replacing it', function () {
    $body = stepBody([], false, [GatewayFactory::fakeTool()], routingFor(['provider_max_price' => ['prompt' => 0.5]]));

    expect($body['provider'])->toEqual(['require_parameters' => true, 'max_price' => ['prompt' => 0.5]]);
});

it('can drop reasoning on the steps that follow tool results', function () {
    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'x';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning' => ['effort' => 'medium']];
        }
    };

    $afterTool = [
        new UserMessage('q'),
        new AssistantMessage('', collect([new ToolCall('c1', 'lookup', [], 'c1')])),
        new ToolResultMessage(collect([new ToolResult('c1', 'lookup', [], 'ok', 'c1')])),
    ];

    $build = fn (array $chat, array $messages): array => GatewayFactory::buildStepBody(
        GatewayFactory::gateway(chat: $chat),
        GatewayFactory::provider(),
        'test/model',
        null,
        $messages,
        [],
        null,
        new TextGenerationOptions(agent: $agent),
        new StepContext(stepNumber: 1),
    );

    expect($build([], $afterTool))->toHaveKey('reasoning')
        ->and($build(['reasoning_on_tool_steps' => false], $afterTool))->not->toHaveKey('reasoning')
        // The first step keeps reasoning whatever the seam says.
        ->and($build(['reasoning_on_tool_steps' => false], [new UserMessage('q')]))->toHaveKey('reasoning');
});

it('never overrides routing the caller already put on the body', function () {
    $routing = routingFor([
        'fallbacks' => ['backup/one'],
        'provider_max_price' => ['prompt' => 0.5],
    ]);

    $gateway = GatewayFactory::gateway(routing: $routing);
    $method = new ReflectionMethod($gateway, 'withServerSideRouting');

    $body = $method->invoke($gateway, [
        'models' => ['caller/choice'],
        'provider' => ['order' => ['groq']],
    ], 'test/model');

    expect($body['models'])->toBe(['caller/choice'])
        ->and($body['provider'])->toBe(['max_price' => ['prompt' => 0.5], 'order' => ['groq']]);
});

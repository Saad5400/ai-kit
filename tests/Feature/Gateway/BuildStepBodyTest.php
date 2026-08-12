<?php

use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Saad\AiKit\Tests\Support\GatewayFactory;

function stepBody(array $config, bool $finalStep, array $tools): array
{
    return GatewayFactory::buildStepBody(
        GatewayFactory::gateway($config),
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

it('forces usage accounting and allows opting out', function () {
    expect(stepBody([], false, [])['usage'])->toBe(['include' => true])
        ->and(stepBody(['force_usage_accounting' => false], false, []))->not->toHaveKey('usage');
});

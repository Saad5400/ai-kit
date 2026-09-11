<?php

use Saad\AiKit\Agents\StepBudget;

it('reads the recommended step budget from the chat config', function () {
    expect(StepBudget::default())->toBe(12);

    config()->set('ai-kit.chat.max_steps', 20);

    expect(StepBudget::default())->toBe(20);
});

it('never goes below two — one tool round plus the tool-less final step', function () {
    config()->set('ai-kit.chat.max_steps', 0);

    expect(StepBudget::default())->toBe(2);
});

<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Serializer;
use Illuminate\JsonSchema\Types\ArrayType;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\AskUser;

it('always pauses, carrying the question as the reason', function () {
    $approval = (new AskUser)->shouldRequestApproval(
        new Request(['question' => 'Which semester?'], 'call-q'),
    );

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe('Which semester?');
});

it('pauses with a null reason when the model sent no usable question', function () {
    $approval = (new AskUser)->shouldRequestApproval(new Request(['question' => '  '], 'call-q'));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBeNull();
});

it('returns the user answer to the model after an edit resume', function () {
    $output = (string) (new AskUser)->handle(
        new Request(['question' => 'Which semester?', 'answer' => 'Fall 2026'], 'call-q'),
    );

    expect($output)->toBe('The user answered: Fall 2026');
});

it('degrades gracefully when the user approved without answering', function () {
    $output = (string) (new AskUser)->handle(new Request(['question' => 'Which semester?'], 'call-q'));

    expect($output)->toContain('did not provide an answer');
});

it('offers suggested answers as an optional 2-4 item schema field', function () {
    $schema = (new AskUser)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['question', 'options'])
        ->and($schema['options'])->toBeInstanceOf(ArrayType::class)
        ->and(Serializer::serialize($schema['options']))
        ->toMatchArray(['type' => 'array', 'minItems' => 2, 'maxItems' => AskUser::MAX_OPTIONS]);
});

it('sanitizes the suggested answers the model proposed', function () {
    expect(AskUser::options(['options' => [' Fall ', 'Spring', 'Fall', '', 'Summer', 'Winter', 'Extra']]))
        // Trimmed, de-duplicated, empties dropped, capped at MAX_OPTIONS.
        ->toBe(['Fall', 'Spring', 'Summer', 'Winter'])
        ->and(AskUser::options(['options' => [2026, 2027]]))->toBe(['2026', '2027'])
        ->and(AskUser::options([]))->toBe([])
        ->and(AskUser::options(['options' => 'Fall']))->toBe([])
        ->and(AskUser::options(['options' => [['nested' => true]]]))->toBe([]);
});

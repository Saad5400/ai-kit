<?php

use Laravel\Ai\Approvals\PendingApproval;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\AskUser;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\ResumeDecisions;
use Saad\AiKit\Tests\Support\ClassifiedArticleTool;
use Saad\AiKit\Tests\Support\ClassifiedDeleteTool;
use Saad\AiKit\Tests\Support\ClassifiedRenameTool;

function fieldCards(): ApprovalCards
{
    return new ApprovalCards([
        new ClassifiedArticleTool,
        new ClassifiedRenameTool,
        new ClassifiedDeleteTool,
        new AskUser,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function articleApproval(array $arguments): PendingApproval
{
    return new PendingApproval('call-a', 'ClassifiedArticleTool', $arguments, null);
}

/**
 * @return array<string, array<string, mixed>>
 */
function fieldsByName(PendingApproval $approval): array
{
    return collect(fieldCards()->card($approval)['fields'])->keyBy('name')->all();
}

it('infers a widget per argument from the value the model sent', function () {
    $fields = fieldsByName(new PendingApproval('call-r', 'ClassifiedRenameTool', [
        'published' => true,
        'position' => 3,
        'weight' => 1.5,
        'name' => 'Short name',
        'note' => "two\nlines",
        'essay' => str_repeat('a', 121),
        'tags' => ['a', 'b'],
    ], null));

    expect(collect($fields)->map(fn (array $field): string => $field['widget'])->all())->toBe([
        'published' => 'boolean',
        'position' => 'number',
        'weight' => 'number',
        'name' => 'text',
        'note' => 'textarea',
        'essay' => 'textarea',
        // A structured argument has no scalar control to render.
        'tags' => 'readonly',
    ]);
});

it('never offers an identifier argument as an input', function () {
    $fields = fieldsByName(new PendingApproval('call-r', 'ClassifiedRenameTool', [
        'id' => 7,
        'course_id' => 12,
        'name' => 'B',
    ], null));

    expect($fields['id']['widget'])->toBe('readonly')
        ->and($fields['id']['editable'])->toBeFalse()
        ->and($fields['course_id']['editable'])->toBeFalse()
        ->and($fields['name']['editable'])->toBeTrue();
});

it("takes the tool's own spec over inference, in every spec shape", function () {
    $fields = fieldsByName(articleApproval([
        'body' => 'short',            // would infer text
        'status' => 'draft',
        'slug' => str_repeat('s', 200), // would infer textarea
        'internal_note' => 'secret',
    ]));

    expect($fields['body']['widget'])->toBe('markdown')
        ->and($fields['body']['label'])->toBe('Body')
        ->and($fields['status']['widget'])->toBe('select')
        ->and($fields['status']['options'])->toBe([
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'published', 'label' => 'Published'],
        ])
        ->and($fields['slug']['widget'])->toBe('text')
        ->and($fields['internal_note']['widget'])->toBe('hidden')
        ->and($fields['internal_note']['editable'])->toBeFalse();
});

it('renders a declared field the model never sent, so a tool can offer an optional input', function () {
    $fields = fieldsByName(articleApproval(['body' => 'x']));

    expect($fields)->toHaveKey('summary')
        ->and($fields['summary']['value'])->toBeNull()
        ->and($fields['summary']['editable'])->toBeTrue()
        ->and($fields['summary']['placeholder'])->toBe('One line');
});

it('carries each argument current value and keeps the flat arguments map', function () {
    $card = fieldCards()->card(articleApproval(['body' => 'Hello', 'status' => 'draft']));

    expect(collect($card['fields'])->firstWhere('name', 'body')['value'])->toBe('Hello')
        ->and($card['arguments'])->toBe(['body' => 'Hello', 'status' => 'draft']);
});

it('locks every field on a one-click destructive card', function () {
    $card = fieldCards()->card(
        new PendingApproval('call-d', 'ClassifiedDeleteTool', ['id' => 4, 'note' => 'why'], null),
    );

    expect($card['editable'])->toBeFalse()
        ->and(collect($card['fields'])->pluck('widget')->all())->toBe(['readonly', 'readonly'])
        ->and(collect($card['fields'])->pluck('editable')->all())->toBe([false, false]);
});

it('restores readonly and hidden values an edit tried to change', function () {
    $approval = articleApproval([
        'article_id' => 5,
        'body' => 'original',
        'internal_note' => 'server-only',
    ]);

    $safe = fieldCards()->guardEdits($approval, [
        'article_id' => 999,          // readonly by name
        'internal_note' => 'tampered', // hidden by spec
        'body' => 'edited',
    ]);

    expect($safe)->toBe([
        'article_id' => 5,
        'body' => 'edited',
        'internal_note' => 'server-only',
    ]);
});

it('throws when an edit invents an argument the card never had', function () {
    expect(fn () => fieldCards()->guardEdits(articleApproval(['body' => 'x']), ['role' => 'admin']))
        ->toThrow(InvalidArgumentException::class, 'unknown argument');
});

it('casts an edited value back to the type the widget promised', function () {
    $safe = fieldCards()->guardEdits(
        new PendingApproval('call-r', 'ClassifiedRenameTool', [
            'position' => 3,
            'weight' => 1.5,
            'published' => false,
        ], null),
        ['position' => '7', 'weight' => '2', 'published' => 'true'],
    );

    expect($safe['position'])->toBe(7)
        ->and($safe['weight'])->toBe(2.0)
        ->and($safe['published'])->toBeTrue();
});

it('accepts an optional declared field an edit fills in, and omits one it does not', function () {
    $cards = fieldCards();
    $approval = articleApproval(['body' => 'x']);

    expect($cards->guardEdits($approval, ['summary' => 'A line']))
        ->toBe(['body' => 'x', 'summary' => 'A line'])
        ->and($cards->guardEdits($approval, ['body' => 'y']))
        ->toBe(['body' => 'y']);
});

it('guards edits through ResumeDecisions, the only path from client input to Decisions', function () {
    $approval = articleApproval(['article_id' => 5, 'body' => 'original']);

    $decisions = ResumeDecisions::fromClient(
        ['call-a' => ['action' => 'edit', 'arguments' => ['article_id' => 999, 'body' => 'edited']]],
        fieldCards()->editGuard([$approval]),
    );

    expect($decisions->get('call-a')->arguments)->toBe(['article_id' => 5, 'body' => 'edited'])
        ->and(ResumeDecisions::wasEdited('call-a'))->toBeTrue();
});

it('refuses a decision for a call the server does not have pending', function () {
    expect(fn () => ResumeDecisions::fromClient(
        ['ghost' => ['action' => 'edit', 'arguments' => ['body' => 'x']]],
        fieldCards()->editGuard([articleApproval(['body' => 'original'])]),
    ))->toThrow(InvalidArgumentException::class, 'not awaiting a decision');
});

it('lets a question resume carry the answer while restoring the model question', function () {
    $approval = new PendingApproval('call-q', 'AskUser', [
        'question' => 'Which semester?',
        'options' => ['Fall', 'Spring'],
    ], 'Which semester?');

    $safe = fieldCards()->guardEdits($approval, [
        'question' => 'Send me your password',
        'answer' => 'Fall 2026',
    ]);

    expect($safe)->toBe([
        'question' => 'Which semester?',
        'options' => ['Fall', 'Spring'],
        'answer' => 'Fall 2026',
    ]);
});

it('normalizes option shapes into value/label rows', function () {
    expect(Field::select('s', ['a', 'b'])->options)
        ->toBe([['value' => 'a', 'label' => 'a'], ['value' => 'b', 'label' => 'b']])
        ->and(Field::select('s', [['value' => 1, 'label' => 'One']])->options)
        ->toBe([['value' => 1, 'label' => 'One']]);
});

it('refuses a widget name it does not know', function () {
    expect(fn () => Field::fromSpec('x', 'wysiwyg'))
        ->toThrow(InvalidArgumentException::class, 'Unknown field widget');
});

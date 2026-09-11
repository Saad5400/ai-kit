<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\ApprovalCards;
use Saad\AiKit\Approvals\Classified\AskUser;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Saad\AiKit\Tests\Support\ClassifiedChapterTool;
use Saad\AiKit\Tests\Support\ClassifiedDeleteTool;
use Saad\AiKit\Tests\Support\ClassifiedRenameTool;

function textCards(): ApprovalCards
{
    return new ApprovalCards([
        new ClassifiedChapterTool,
        new ClassifiedDeleteTool,
        new ClassifiedRenameTool,
        new AskUser,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function chapterApproval(array $arguments = []): PendingApproval
{
    return new PendingApproval('call-1', 'ClassifiedChapterTool', [
        'track_id' => 'v6oPvGqX',
        'name' => 'المصفوفات',
        'free' => false,
        'internal_note' => 'not for the reader',
        ...$arguments,
    ], null);
}

it('renders one card as plain Arabic text: title, tool, fields, preview', function () {
    expect(textCards()->text(chapterApproval(), 'ar'))->toBe(implode("\n", [
        'إنشاء فصل «المصفوفات»',
        'الأداة: Classified Chapter Tool',
        '• الاسم: المصفوفات',
        '• مجاني: لا',
        '• يُنشأ في نهاية المسار.',
    ]));
});

it('mirrors the same card in English', function () {
    expect(textCards()->text(chapterApproval(['name' => 'Arrays', 'free' => true]), 'en'))->toBe(implode("\n", [
        'Create chapter “Arrays”',
        'Tool: Classified Chapter Tool',
        '• Name: Arrays',
        '• Free: Yes',
        '• Added at the end of the track.',
    ]));
});

it('never leaks HTML or Markdown of its own into the text', function () {
    $text = textCards()->text(chapterApproval(['name' => '<b>a & b</b>']), 'ar');

    // The value passes through verbatim — escaping belongs to the transport
    // that adds markup, not here.
    expect($text)->toContain('• الاسم: <b>a & b</b>')
        ->and($text)->not->toContain('&amp;')
        ->and($text)->not->toMatch('/[*_`\[\]]/');
});

it('warns on a destructive call, and stays quiet on an undoable one', function () {
    $destructive = textCards()->text(
        new PendingApproval('call-2', 'ClassifiedDeleteTool', ['id' => 4], 'Permanently deletes the widget.'),
        'ar',
    );

    expect($destructive)->toBe(implode("\n", [
        // No title() of its own, so the humanized tool name IS the title and
        // is not repeated as a tool line. No fields: `id` addresses the
        // record. No preview.
        'Classified Delete Tool',
        '⚠️ لا يمكن التراجع عن هذا الإجراء.',
        'السبب: Permanently deletes the widget.',
    ]))
        ->and(textCards()->text(chapterApproval(), 'ar'))
        ->not->toContain('⚠️');
});

it('renders an empty preview, an unset argument and a hidden one as nothing at all', function () {
    $text = textCards()->text(
        new PendingApproval('call-3', 'ClassifiedRenameTool', ['name' => 'B', 'note' => null, 'tags' => []], null),
        'en',
    );

    // ClassifiedRenameTool declares no title, preview or fields, so the
    // humanized tool name is the whole card and `name` is inferred; the null
    // and the empty array print nothing rather than an empty row.
    expect($text)->toBe("Classified Rename Tool\n• Name: B")
        ->and(textCards()->text(chapterApproval(), 'ar'))
        ->not->toContain('not for the reader')
        // A declared-but-unfilled optional input has nothing to preview.
        ->not->toContain('Summary');
});

it('keeps the identity argument off the text, the way the web card tucks it away', function () {
    expect(textCards()->text(chapterApproval(), 'ar'))
        ->not->toContain('v6oPvGqX')
        ->not->toContain('Track');
});

it('caps a long value and flattens a multi-line one onto its own row', function () {
    $text = textCards()->text(chapterApproval([
        'summary' => str_repeat('ا', 400),
        'name' => "two\nlines",
    ]), 'ar');

    $summary = collect(explode("\n", $text))->first(fn (string $line): bool => str_starts_with($line, '• Summary: '));

    expect($text)->toContain('• الاسم: two lines')
        ->and(mb_strlen((string) $summary))->toBe(mb_strlen('• Summary: ') + 161)
        ->and($summary)->toEndWith('…')
        // A newline inside a value never becomes another row: title, tool,
        // three fields, one preview line.
        ->and(substr_count($text, "\n"))->toBe(5);
});

it('renders a structured value as JSON, unescaped', function () {
    expect(textCards()->text(chapterApproval(['summary' => ['ar' => 'مرحبا', 'url' => 'https://a/b']]), 'ar'))
        ->toContain('• Summary: {"ar":"مرحبا","url":"https://a/b"}');
});

it('renders an AskUser pause as the question and its suggested answers', function () {
    expect(textCards()->text(
        new PendingApproval('call-4', 'AskUser', [
            'question' => 'أي فصل دراسي؟',
            'options' => ['الأول', 'الثاني'],
        ], null),
        'ar',
    ))->toBe(implode("\n", [
        'أي فصل دراسي؟',
        '• الأول',
        '• الثاني',
    ]));
});

it('switches the locale for the app copy too, and puts it back', function () {
    App::setLocale('en');

    $arabic = textCards()->text(chapterApproval(), 'ar');

    expect($arabic)->toContain('إنشاء فصل «المصفوفات»')
        ->and($arabic)->toContain('• الاسم: ')
        ->and(App::getLocale())->toBe('en');
});

it('accepts a map-shaped preview, humanizing its keys like the web card does', function () {
    $tool = new class extends ClassifiedTool
    {
        public function name(): string
        {
            return 'MapPreviewTool';
        }

        public function capability(): Capability
        {
            return Capability::write(undoable: true);
        }

        public function description(): Stringable|string
        {
            return 'Moves a lesson.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function title(Request $request): string
        {
            return 'نقل الدرس';
        }

        public function preview(Request $request): array
        {
            return ['track_id' => 'الأساسيات', 'position' => 3, 'note' => ''];
        }

        protected function perform(Request $request): Stringable|string
        {
            return 'moved';
        }
    };

    expect((new ApprovalCards([$tool]))->text(new PendingApproval('call-5', 'MapPreviewTool', [], null), 'ar'))
        ->toBe(implode("\n", [
            'نقل الدرس',
            'الأداة: Map Preview Tool',
            '• Track: الأساسيات',
            '• Position: 3',
        ]));
});

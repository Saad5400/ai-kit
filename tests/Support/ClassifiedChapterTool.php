<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\FieldWidget;
use Stringable;

/**
 * A payload write whose card copy is written in the reader's language — the
 * shape both consumers ship, and what {@see ApprovalCards::text()} has to
 * honour: the tool resolves its title, preview and labels while the card is
 * being built, so the locale must already be switched by then.
 */
class ClassifiedChapterTool extends ClassifiedTool
{
    public function capability(): Capability
    {
        return Capability::write(undoable: true);
    }

    public function description(): Stringable|string
    {
        return 'Creates a chapter.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function title(Request $request): string
    {
        return $this->arabic()
            ? 'إنشاء فصل «'.$request['name'].'»'
            : 'Create chapter “'.$request['name'].'”';
    }

    public function preview(Request $request): array
    {
        return [
            // Repeats the title — dropped, exactly as on the web card.
            $this->title($request),
            $this->arabic() ? 'يُنشأ في نهاية المسار.' : 'Added at the end of the track.',
        ];
    }

    public function fields(): array
    {
        return [
            'track_id' => FieldWidget::Readonly,
            'name' => Field::make('name', FieldWidget::Text, label: $this->arabic() ? 'الاسم' : 'Name'),
            'free' => Field::make('free', FieldWidget::Boolean, label: $this->arabic() ? 'مجاني' : 'Free'),
            'internal_note' => 'hidden',
            'summary' => Field::make('summary', FieldWidget::Textarea),
        ];
    }

    protected function perform(Request $request): Stringable|string
    {
        return 'created';
    }

    private function arabic(): bool
    {
        return App::getLocale() === 'ar';
    }
}

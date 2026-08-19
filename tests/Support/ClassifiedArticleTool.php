<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\FieldWidget;
use Stringable;

/** A payload write that declares its own form: every field-spec shape at once. */
class ClassifiedArticleTool extends ClassifiedTool
{
    /** @var array<string, mixed>|null */
    public ?array $received = null;

    public function capability(): Capability
    {
        return Capability::write(undoable: true);
    }

    public function description(): Stringable|string
    {
        return 'Publishes an article.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            'body' => Field::make('body', FieldWidget::Markdown, label: 'Body'),
            'status' => ['widget' => 'select', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
            'slug' => FieldWidget::Text,
            'internal_note' => 'hidden',
            // Declared but rarely sent by the model — an optional input.
            'summary' => Field::make('summary', FieldWidget::Textarea, placeholder: 'One line'),
        ];
    }

    protected function perform(Request $request): Stringable|string
    {
        $this->received = $request->all();

        return 'published';
    }
}

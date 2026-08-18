<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Stringable;

/** A destructive write — always pauses the turn. */
class ClassifiedDeleteTool extends ClassifiedTool
{
    public function capability(): Capability
    {
        return Capability::destructive('Permanently deletes the widget.');
    }

    public function description(): Stringable|string
    {
        return 'Deletes a widget.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function perform(Request $request): Stringable|string
    {
        return 'deleted';
    }
}

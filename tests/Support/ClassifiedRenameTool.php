<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Stringable;

/** An undoable payload write — the auto-execute tier when the ledger is on. */
class ClassifiedRenameTool extends ClassifiedTool
{
    public int $performed = 0;

    public function __construct(private bool $undoable = true) {}

    public function capability(): Capability
    {
        return Capability::write(undoable: $this->undoable);
    }

    public function description(): Stringable|string
    {
        return 'Renames a widget.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function perform(Request $request): Stringable|string
    {
        $this->performed++;

        return 'renamed to '.$request['name'];
    }

    protected function compensation(Request $request, string $result): ?array
    {
        return ['op' => 'rename', 'name' => 'previous'];
    }

    protected function executedBy(): ?string
    {
        return 'user:9';
    }
}

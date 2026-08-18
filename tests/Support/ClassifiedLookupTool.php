<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Stringable;

/** A read — never gated, never ledgered. */
class ClassifiedLookupTool extends ClassifiedTool
{
    public function capability(): Capability
    {
        return Capability::read();
    }

    public function description(): Stringable|string
    {
        return 'Looks a widget up.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function perform(Request $request): Stringable|string
    {
        return 'found it';
    }
}

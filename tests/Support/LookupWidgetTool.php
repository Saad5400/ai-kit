<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class LookupWidgetTool implements Tool
{
    public function name(): string
    {
        return 'LookupWidget';
    }

    public function description(): string
    {
        return 'Looks a widget up.';
    }

    public function handle(Request $request): string
    {
        return 'widget 4 is the secret sprocket';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()];
    }
}

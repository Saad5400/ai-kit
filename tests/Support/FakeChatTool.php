<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class FakeChatTool implements Tool
{
    public function __construct(protected mixed $actor) {}

    public function description(): Stringable|string
    {
        return 'Fake tool for adapter tests.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($request->string('query')->value() === 'boom') {
            throw new RuntimeException('exploded');
        }

        return sprintf('%s searched for %s', $this->actor, $request->string('query')->value());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search for.')->required(),
        ];
    }
}

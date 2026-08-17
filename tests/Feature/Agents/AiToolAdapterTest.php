<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Saad\AiKit\Agents\DestructiveToolAdapter;
use Saad\AiKit\Agents\ReadToolAdapter;
use Saad\AiKit\Agents\WriteToolAdapter;
use Saad\AiKit\Tests\Support\FakeChatTool;

it('derives snake name, headline title, and the wrapped description', function () {
    $adapter = new ReadToolAdapter(FakeChatTool::class, 'actor-1');

    expect($adapter->name())->toBe('fake_chat_tool')
        ->and($adapter->title())->toBe('Fake Chat Tool')
        ->and($adapter->description())->toBe('Fake tool for adapter tests.');
});

it('forwards the wrapped schema verbatim', function () {
    $adapter = new ReadToolAdapter(FakeChatTool::class, 'actor-1');

    $fields = $adapter->schema(new JsonSchemaTypeFactory);

    expect(array_keys($fields))->toBe(['query']);
});

it('runs the wrapped tool as the actor and passes text through', function () {
    $adapter = new ReadToolAdapter(FakeChatTool::class, 'actor-1');

    $response = $adapter->handle(new McpRequest(['query' => 'grades']));

    expect((string) $response->content())->toBe('actor-1 searched for grades');
});

it('wraps a throwing tool as a tool error, never an exception page', function () {
    $adapter = new WriteToolAdapter(FakeChatTool::class, 'actor-1');

    $response = $adapter->handle(new McpRequest(['query' => 'boom']));

    expect((string) $response->content())->toBe(__('ai-kit::agents.tool_failed'))
        ->and($response->isError())->toBeTrue();
});

it('annotates read, write and destructive adapters correctly', function () {
    $annotationValue = function (string $adapterClass, string $annotation): ?bool {
        $attributes = (new ReflectionClass($adapterClass))->getAttributes($annotation);

        return $attributes === [] ? null : $attributes[0]->newInstance()->value;
    };

    expect($annotationValue(ReadToolAdapter::class, IsReadOnly::class))->toBeTrue()
        ->and($annotationValue(WriteToolAdapter::class, IsDestructive::class))->toBeFalse()
        // PHP attributes are not inherited — the destructive subclass exists
        // to carry the flag.
        ->and($annotationValue(DestructiveToolAdapter::class, IsDestructive::class))->toBeTrue();
});

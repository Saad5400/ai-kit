<?php

namespace Saad\AiKit\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Exposes one laravel/ai chat tool over laravel/mcp. The two tool contracts
 * share the JsonSchema builder and a near-identical request API, so the
 * adapter translates only the ENVELOPES — the wrapped tool keeps owning its
 * description, schema, validation and authorization, which is what keeps
 * chat and MCP behaviour from drifting.
 *
 * Naming: `BrowseCatalog` → `browse_catalog` (snake name, headline title).
 * A probe instance built at construction serves description/schema;
 * {@see handle} implementations build a FRESH wrapped instance per call.
 *
 * `actor` is the app's acting principal (a user model, an owner key) —
 * passed to the wrapped tool's constructor by the default {@see newWrapped};
 * apps with richer constructor shapes override it.
 */
abstract class AiToolAdapter extends McpTool
{
    public function __construct(
        protected string $toolClass,
        protected mixed $actor,
    ) {
        $this->name = Str::snake(class_basename($toolClass));
        $this->title = Str::headline(class_basename($toolClass));
        $this->description = $this->adaptDescription((string) $this->newWrapped()->description());
    }

    /**
     * Schema forwarding is verbatim: both packages accept the same builder.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->newWrapped()->schema($schema);
    }

    /**
     * Rewrite the chat-facing description for MCP semantics (e.g. strip
     * confirmation wording a chat card implies but MCP cannot offer).
     */
    protected function adaptDescription(string $description): string
    {
        return $description;
    }

    protected function newWrapped(): AiTool
    {
        return new ($this->toolClass)($this->actor);
    }

    protected function request(McpRequest $request): AiRequest
    {
        return new AiRequest($request->all());
    }
}

<?php

namespace Saad\AiKit\Agents;

use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * A read tool over MCP: run the wrapped tool and pass its text through —
 * including its refusal/"not found" messages, which read the same as in
 * chat because the wrapped tool already scopes every query to the actor.
 * Reads are never metered.
 */
#[IsReadOnly]
class ReadToolAdapter extends AiToolAdapter
{
    public function handle(McpRequest $request): Response
    {
        try {
            return Response::text((string) $this->newWrapped()->handle($this->request($request)));
        } catch (Throwable $exception) {
            report($exception);

            return Response::error(__('ai-kit::agents.tool_failed'));
        }
    }
}

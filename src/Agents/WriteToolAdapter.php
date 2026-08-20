<?php

namespace Saad\AiKit\Agents;

use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

/**
 * A write tool over MCP runs in IMMEDIATE mode: no interactive confirm
 * surface exists here, so the external client's own tool-approval prompt is
 * the confirmation step. The wrapped tool still executes as the actor and
 * under the same per-tool authorization as chat. Failures return
 * Response::error — never an unhandled exception page.
 *
 * Apps gate registration (hide the write surface from actors who cannot
 * write) by overriding {@see shouldRegister}, and route execution through
 * their own runner (an action runner, an audit ledger) by overriding
 * {@see handle}. A tool that is a `Classified\ClassifiedTool` in chat runs
 * here with its pause skipped — the MCP client owns the confirmation.
 */
#[IsDestructive(false)]
class WriteToolAdapter extends AiToolAdapter
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

    public function shouldRegister(): bool
    {
        return true;
    }
}

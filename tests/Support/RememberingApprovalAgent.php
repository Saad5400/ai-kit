<?php

namespace Saad\AiKit\Tests\Support;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A remembering agent with one auto-run tool (LookupWidget) and one gated
 * tool (DeleteWidget) — the smallest agent that can pause for approval and
 * resume through the bound conversation store.
 */
class RememberingApprovalAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You manage widgets.';
    }

    public function tools(): iterable
    {
        return [new LookupWidgetTool, new DeleteWidgetTool];
    }
}

<?php

namespace Saad\AiKit\Approvals;

/**
 * How the assistant's write tools behave this turn. Resolved ONLY from
 * runtime turn context ({@see WriteGate::enterPropose()} /
 * {@see WriteGate::enterExecute()}) — never from a tool's schema or the
 * system prompt — so the model's cached prompt prefix stays byte-identical
 * across modes (the provider prompt-cache constraint).
 */
enum WriteGateMode
{
    /**
     * The container default (MCP calls, direct tool tests): writes are
     * collected for the caller to run right away — the external client's own
     * tool-approval prompt is the confirm step.
     */
    case Immediate;

    /**
     * The in-app planning turn: write tools build and REGISTER proposals for
     * the plan/action card but nothing executes until a human approves.
     */
    case Propose;

    /**
     * The confirm turn: approved proposals run for real, gated to the
     * approved plan's blast radius by {@see WriteGate::guard()}.
     */
    case Execute;
}

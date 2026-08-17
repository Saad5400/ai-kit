<?php

namespace Saad\AiKit\Streaming;

use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;

/**
 * What one folded stream produced: the bookkeeping the mapper collects while
 * mapping (whether or not the corresponding wire events were emitted), handed
 * to the `done`-payload closure and returned to the caller. Tool results feed
 * post-stream extension events (uqucc extracts citations from them); usage
 * feeds spend recording.
 */
class StreamResult
{
    public bool $failed = false;

    public ?Error $error = null;

    /** The text actually emitted on the wire, after the transformer pipeline. */
    public string $text = '';

    /** @var list<ToolCall> */
    public array $toolCalls = [];

    /** @var list<ToolResult> */
    public array $toolResults = [];

    public ?Usage $usage = null;
}

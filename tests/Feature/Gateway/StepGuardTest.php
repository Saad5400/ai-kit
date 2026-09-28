<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Gateway\ReasoningOpenRouterGateway;
use Saad\AiKit\Gateway\WrapUpInstruction;
use Saad\AiKit\Support\TurnContext;
use Saad\AiKit\Tests\Support\GatewayFactory;
use Saad\AiKit\Tests\Support\OpenRouterSse;

afterEach(fn () => WrapUpInstruction::using(null));

/** A tool the loop can resolve by name. */
function lookupTool(): Tool
{
    return new class implements Tool
    {
        public function name(): string
        {
            return 'lookup';
        }

        public function description(): string
        {
            return 'Looks things up.';
        }

        public function handle(Request $request): string
        {
            return 'found '.($request['q'] ?? '');
        }

        public function schema(JsonSchema $schema): array
        {
            return ['q' => $schema->string()];
        }
    };
}

/** A history that just ran a tool: user → assistant(tool_call) → tool result. */
function afterToolMessages(): array
{
    return [
        new UserMessage('what courses do I have?'),
        new AssistantMessage('لنبحث أولاً عن مقرراتك.', collect([new ToolCall('c1', 'lookup', ['q' => 'courses'], 'c1')])),
        new ToolResultMessage(collect([new ToolResult('c1', 'lookup', ['q' => 'courses'], 'found courses', 'c1')])),
    ];
}

function guardChunk(array $delta, ?string $finishReason = null, string $provider = 'DeepSeek'): array
{
    return OpenRouterSse::chunk($delta, $finishReason) + ['provider' => $provider];
}

/** A streamed reply of plain text ending in `stop`. */
function textStream(string ...$parts): string
{
    $frames = array_map(fn (string $part) => guardChunk(['content' => $part]), $parts);
    $frames[] = guardChunk([], finishReason: 'stop');
    $frames[] = OpenRouterSse::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001]);

    return OpenRouterSse::body($frames);
}

/** A streamed step that finished with `stop` and no content at all. */
function blankStream(): string
{
    return OpenRouterSse::body([
        guardChunk(['role' => 'assistant']),
        guardChunk([], finishReason: 'stop'),
        OpenRouterSse::usageFrame(['prompt_tokens' => 7, 'completion_tokens' => 0, 'cost' => 0.0005]),
    ]);
}

/** A streamed step that ends in one structured `lookup` call. */
function toolCallStream(string $narration = ''): string
{
    $frames = $narration === '' ? [] : [guardChunk(['content' => $narration])];
    $frames[] = guardChunk(['tool_calls' => [['index' => 0, 'id' => 'c9', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"x"}']]]], finishReason: 'tool_calls');
    $frames[] = OpenRouterSse::usageFrame(['prompt_tokens' => 3, 'completion_tokens' => 2, 'cost' => 0.0002]);

    return OpenRouterSse::body($frames);
}

function guardedGateway(array $config = [], array $chat = []): ReasoningOpenRouterGateway
{
    return GatewayFactory::gateway(['retry' => ['attempts' => 1]] + $config, chat: $chat);
}

/**
 * Drive one streamed step; returns [events, step].
 *
 * @return array{0: array, 1: StepResponse|null}
 */
function guardedStream(ReasoningOpenRouterGateway $gateway, array $messages, array $tools = [], bool $finalStep = false): array
{
    $generator = $gateway->generateStreamStep(
        'inv-guard', GatewayFactory::provider(), 'test/model', 'be helpful', $messages, $tools, null, null, null,
        new StepContext(stepNumber: $finalStep ? 3 : 1, isFinalStep: $finalStep),
    );

    $events = [];

    foreach ($generator as $event) {
        $events[] = $event;
    }

    return [$events, $generator->getReturn()];
}

function wireText(array $events): string
{
    return implode('', array_map(fn ($e) => $e->delta, array_filter($events, fn ($e) => $e instanceof TextDelta)));
}

/** The decoded JSON bodies of every request sent, in order. */
function sentBodies(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->data())->all();
}

// ---------------------------------------------------------------------------
// Wrap-up: blank final text after tool activity
// ---------------------------------------------------------------------------

it('appends a tool-less wrap-up when a step after tool results comes back blank', function () {
    Http::fake(['*' => Http::sequence()
        ->push(blankStream())
        ->push(textStream('You have ', 'two courses.')),
    ]);

    [$events, $step] = guardedStream(guardedGateway(), afterToolMessages(), [lookupTool()]);

    $bodies = sentBodies();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[1])->not->toHaveKeys(['tools', 'tool_choice'])
        ->and(end($bodies[1]['messages'])['role'])->toBe('user')
        ->and(end($bodies[1]['messages'])['content'])->toContain('Tool steps are over')
        // The blank step contributes no assistant message to the wrap-up history.
        ->and(array_column($bodies[1]['messages'], 'role'))->toBe(['system', 'user', 'assistant', 'tool', 'user'])
        ->and(wireText($events))->toBe('You have two courses.')
        ->and($step->text)->toBe('You have two courses.')
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->usage->inputTokens)->toBe(17)
        ->and($step->usage->outputTokens)->toBe(5)
        ->and(TurnContext::flags())->toBe(['wrap_up' => 'blank_final']);
});

it('leaves a step that answered alone — one request, no flags, identical events', function () {
    $reply = textStream('You have ', 'two courses.');

    Http::fake(['*' => Http::sequence()->push($reply)->push($reply)]);

    [$guarded] = guardedStream(guardedGateway(), afterToolMessages(), [lookupTool()]);
    [$bare] = guardedStream(guardedGateway(chat: ['wrap_up' => ['on_blank_final' => false, 'on_exhaustion' => false]]), afterToolMessages(), [lookupTool()]);

    // One request each — the guard added nothing.
    expect(Http::recorded())->toHaveCount(2)
        ->and(array_map(get_class(...), $guarded))->toBe(array_map(get_class(...), $bare))
        ->and(wireText($guarded))->toBe('You have two courses.')
        ->and(TurnContext::flags())->toBe([]);
});

it('does not wrap up a blank first reply that involved no tools', function () {
    Http::fake(['*' => Http::response(blankStream())]);

    [, $step] = guardedStream(guardedGateway(), [new UserMessage('hi')], [lookupTool()]);

    expect(Http::recorded())->toHaveCount(1)
        ->and($step->text)->toBe('')
        ->and(TurnContext::flags())->toBe([]);
});

it('honors the blank-final toggle', function () {
    Http::fake(['*' => Http::response(blankStream())]);

    [, $step] = guardedStream(guardedGateway(chat: ['wrap_up' => ['on_blank_final' => false]]), afterToolMessages(), [lookupTool()]);

    expect(Http::recorded())->toHaveCount(1)
        ->and($step->text)->toBe('');
});

it('wraps up on the non-streamed path too', function () {
    Http::fake(['*' => Http::sequence()
        ->push(OpenRouterSse::completion('') + ['provider' => 'DeepSeek'])
        ->push(OpenRouterSse::completion('Two courses.')),
    ]);

    $step = guardedGateway()->generateTextStep(
        GatewayFactory::provider(), 'test/model', null, afterToolMessages(), [lookupTool()], null, null, null, new StepContext(stepNumber: 1),
    );

    expect(Http::recorded())->toHaveCount(2)
        ->and(sentBodies()[1])->not->toHaveKey('tools')
        ->and($step->text)->toBe('Two courses.')
        ->and(TurnContext::flags())->toBe(['wrap_up' => 'blank_final']);
});

// ---------------------------------------------------------------------------
// Wrap-up: step budget exhausted with tool calls still pending
// ---------------------------------------------------------------------------

it('appends an answer when the final step still ended in tool calls, keeping the calls for the loop', function () {
    Http::fake(['*' => Http::sequence()
        ->push(toolCallStream('Let me check one more thing.'))
        ->push(textStream('I ran out of steps; here is what I found so far.')),
    ]);

    $gateway = guardedGateway(['final_step' => ['withhold_tools' => false]]);

    [$events, $step] = guardedStream($gateway, afterToolMessages(), [lookupTool()], finalStep: true);

    $bodies = sentBodies();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[1])->not->toHaveKey('tools')
        // The narration rides into the wrap-up history as a plain assistant turn — never its tool_calls.
        ->and(array_column($bodies[1]['messages'], 'role'))->toBe(['system', 'user', 'assistant', 'tool', 'assistant', 'user'])
        ->and($bodies[1]['messages'][4])->toBe(['role' => 'assistant', 'content' => 'Let me check one more thing.'])
        ->and($step->toolCalls)->toHaveCount(1)
        ->and($step->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($step->text)->toBe("Let me check one more thing.\n\nI ran out of steps; here is what I found so far.")
        ->and(wireText($events))->toBe($step->text)
        // What the SDK persists: TextDelta::combine() cuts steps at each
        // StreamStart, so the wrap-up's own must not reach the stream — it
        // would add a second blank line on top of the separator.
        ->and(TextDelta::combine($events))->toBe($step->text)
        ->and(array_filter($events, fn ($e) => $e instanceof StreamStart))->toHaveCount(1)
        ->and(array_filter($events, fn ($e) => $e instanceof ToolCallEvent))->toHaveCount(1)
        ->and(TurnContext::flags())->toBe(['wrap_up' => 'step_exhaustion']);
});

it('does not treat tool calls on a non-final step as exhaustion', function () {
    Http::fake(['*' => Http::response(toolCallStream())]);

    [, $step] = guardedStream(guardedGateway(['final_step' => ['withhold_tools' => false]]), afterToolMessages(), [lookupTool()]);

    expect(Http::recorded())->toHaveCount(1)
        ->and($step->toolCalls)->toHaveCount(1)
        ->and(TurnContext::flags())->toBe([]);
});

it('honors the exhaustion toggle', function () {
    Http::fake(['*' => Http::response(toolCallStream())]);

    guardedStream(guardedGateway(['final_step' => ['withhold_tools' => false]], ['wrap_up' => ['on_exhaustion' => false]]), afterToolMessages(), [lookupTool()], finalStep: true);

    expect(Http::recorded())->toHaveCount(1);
});

/** A wrap-up that streams some text, then OpenRouter's mid-stream error frame. */
function erroringWrapUpStream(): string
{
    return OpenRouterSse::body([
        guardChunk(['content' => 'Done: I fou']),
        ['id' => 'gen-wrap', 'error' => ['code' => 502, 'message' => 'Provider returned error']],
    ]);
}

it('fails the step when a blank-final wrap-up errors mid-stream, instead of passing the blank step off as a success', function () {
    Http::fake(['*' => Http::sequence()->push(blankStream())->push(erroringWrapUpStream())]);

    [$events, $step] = guardedStream(guardedGateway(), afterToolMessages(), [lookupTool()]);

    // null makes stock's loop throw StreamErrorException on the next pull.
    expect($step)->toBeNull()
        ->and(array_filter($events, fn ($e) => $e instanceof Error))->toHaveCount(1)
        ->and(wireText($events))->toBe('Done: I fou');
});

it('fails the step when a step-exhaustion wrap-up errors, so its tool calls never run', function () {
    Http::fake(['*' => Http::sequence()->push(toolCallStream('Let me check one more thing.'))->push(erroringWrapUpStream())]);

    [$events, $step] = guardedStream(guardedGateway(['final_step' => ['withhold_tools' => false]]), afterToolMessages(), [lookupTool()], finalStep: true);

    // Returning the pre-wrap-up step would hand the loop its tool call to run after the wire said `error`.
    expect($step)->toBeNull()
        ->and(TurnContext::flags())->toBe(['wrap_up' => 'step_exhaustion']);
});

it('resolves the wrap-up instruction from the closure seam first', function () {
    Http::fake(['*' => Http::sequence()->push(blankStream())->push(textStream('ok'))]);

    WrapUpInstruction::using(fn (string $reason): string => "closure says {$reason}");
    guardedStream(guardedGateway(chat: ['wrap_up' => ['instruction' => 'ignored']]), afterToolMessages(), [lookupTool()]);

    expect(end(sentBodies()[1]['messages'])['content'])->toBe('closure says blank_final');
});

it('resolves a configured instruction as a lang key when one exists', function () {
    Http::fake(['*' => Http::sequence()->push(blankStream())->push(textStream('ok'))]);

    guardedStream(guardedGateway(chat: ['wrap_up' => ['instruction' => 'ai-kit::streaming.stale']]), afterToolMessages(), [lookupTool()]);

    expect(end(sentBodies()[1]['messages'])['content'])->toBe(__('ai-kit::streaming.stale'));
});

it('resolves a configured instruction as literal text otherwise', function () {
    Http::fake(['*' => Http::sequence()->push(blankStream())->push(textStream('ok'))]);

    guardedStream(guardedGateway(chat: ['wrap_up' => ['instruction' => 'Answer now please.']]), afterToolMessages(), [lookupTool()]);

    expect(end(sentBodies()[1]['messages'])['content'])->toBe('Answer now please.');
});

// ---------------------------------------------------------------------------
// Markup leak: salvage → retry → wrap-up
// ---------------------------------------------------------------------------

it('salvages a leaked DSML call to an offered tool as a real tool call', function () {
    Http::fake(['*' => Http::response(OpenRouterSse::body([
        guardChunk(['content' => 'لنبحث أولاً. ']),
        guardChunk(['content' => '<｜DSML｜tool_calls><｜DSML｜invoke name="lookup"><｜DSML｜parameter name="q" string="true">courses</｜DSML｜parameter></｜DSML｜invoke></｜DSML｜tool_calls>']),
        guardChunk([], finishReason: 'stop'),
    ]))]);

    [$events, $step] = guardedStream(guardedGateway(), [new UserMessage('courses?')], [lookupTool()]);

    $toolEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

    expect(Http::recorded())->toHaveCount(1)
        ->and(wireText($events))->toBe('لنبحث أولاً. ')
        ->and($step->toolCalls)->toHaveCount(1)
        ->and($step->toolCalls[0]->name)->toBe('lookup')
        ->and($step->toolCalls[0]->arguments)->toBe(['q' => 'courses'])
        ->and($step->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($toolEvents)->toHaveCount(1)
        ->and($toolEvents[0]->toolCall->id)->toBe($step->toolCalls[0]->id)
        ->and(TurnContext::flags())->toBe(['markup_leak' => true, 'markup_salvaged' => true]);
});

it('retries a leaked step once, excluding the upstream that leaked', function () {
    Http::fake(['*' => Http::sequence()
        ->push(OpenRouterSse::body([
            guardChunk(['content' => 'Checking. ']),
            guardChunk(['content' => '<｜DSML｜invoke name="not_offered"></｜DSML｜invoke>'], finishReason: 'stop'),
        ]))
        ->push(toolCallStream())
        ->push(textStream('fine')),
    ]);

    $gateway = guardedGateway();

    [$events, $step] = guardedStream($gateway, [new UserMessage('courses?')], [lookupTool()]);

    // The exclusion is consumed by that one body: the SAME gateway's next
    // request (a long-lived instance serves a whole worker) is clean.
    guardedStream($gateway, [new UserMessage('again')], [lookupTool()]);

    $bodies = sentBodies();

    expect($bodies)->toHaveCount(3)
        ->and($bodies[0]['provider']['ignore'] ?? null)->toBeNull()
        ->and($bodies[1]['provider']['ignore'])->toBe(['DeepSeek'])
        ->and($bodies[1]['messages'])->toBe($bodies[0]['messages'])
        ->and($bodies[2]['provider']['ignore'] ?? null)->toBeNull()
        ->and(wireText($events))->toBe('Checking. ')
        ->and($step->text)->toBe('Checking. ')
        ->and(TextDelta::combine($events))->toBe($step->text)
        ->and(array_filter($events, fn ($e) => $e instanceof StreamStart))->toHaveCount(1)
        ->and($step->toolCalls)->toHaveCount(1)
        ->and($step->markupLeaked)->toBeFalse()
        ->and(TurnContext::flags())->toBe(['markup_leak' => true, 'markup_retried' => true]);
});

it('can retry without excluding the provider', function () {
    $leak = OpenRouterSse::body([guardChunk(['content' => '<|DSML|invoke name="x"></|DSML|invoke>'], finishReason: 'stop')]);

    Http::fake(['*' => Http::sequence()->push($leak)->push(textStream('ok'))]);
    guardedStream(guardedGateway(['markup_leak' => ['ignore_provider' => false]]), [new UserMessage('hi')], [lookupTool()]);

    expect(Http::recorded())->toHaveCount(2)
        ->and(sentBodies()[1]['provider'] ?? [])->not->toHaveKey('ignore');
});

it('can be told not to retry a leaked step at all', function () {
    $leak = OpenRouterSse::body([guardChunk(['content' => '<|DSML|invoke name="x"></|DSML|invoke>'], finishReason: 'stop')]);

    Http::fake(['*' => Http::sequence()->push($leak)->push(textStream('never'))]);
    [, $step] = guardedStream(guardedGateway(['markup_leak' => ['retry' => false]], ['wrap_up' => ['on_blank_final' => false]]), [new UserMessage('hi')], [lookupTool()]);

    expect(Http::recorded())->toHaveCount(1)
        ->and($step->text)->toBe('');
});

it('falls through to a wrap-up when the retry is still blank after stripping', function () {
    $leak = OpenRouterSse::body([guardChunk(['content' => '<｜DSML｜invoke name="x"></｜DSML｜invoke>'], finishReason: 'stop')]);

    Http::fake(['*' => Http::sequence()
        ->push($leak)
        ->push($leak)
        ->push(textStream('I could not run the lookup; please try again.')),
    ]);

    [$events, $step] = guardedStream(guardedGateway(), [new UserMessage('hi')], [lookupTool()]);

    expect(Http::recorded())->toHaveCount(3)
        ->and(sentBodies()[2])->not->toHaveKey('tools')
        ->and(wireText($events))->toBe('I could not run the lookup; please try again.')
        ->and($step->text)->toBe(wireText($events))
        ->and(TurnContext::flags())->toBe(['markup_leak' => true, 'markup_retried' => true, 'wrap_up' => 'blank_final']);
});

it('replaces, rather than appends, a leaked non-streamed attempt on retry', function () {
    Http::fake(['*' => Http::sequence()
        ->push(OpenRouterSse::completion('Checking. <｜DSML｜invoke name="x"></｜DSML｜invoke>') + ['provider' => 'DeepSeek'])
        ->push(OpenRouterSse::completion('Here you go.')),
    ]);

    $step = guardedGateway()->generateTextStep(
        GatewayFactory::provider(), 'test/model', null, [new UserMessage('hi')], [lookupTool()], null, null, null, new StepContext,
    );

    expect(Http::recorded())->toHaveCount(2)
        ->and(sentBodies()[1]['provider']['ignore'])->toBe(['DeepSeek'])
        ->and($step->text)->toBe('Here you go.');
});

// ---------------------------------------------------------------------------
// Through laravel/ai's own loop
// ---------------------------------------------------------------------------

it('ends a budget-exhausted loop on a real answer: tools withheld on the last step, wrap-up when it goes blank', function () {
    Http::fake(['*' => Http::sequence()
        ->push(toolCallStream('لنبحث أولاً عن مقرراتك.'))   // step 0: calls lookup
        ->push(blankStream())                              // step 1 (final, no tools): silent
        ->push(textStream('You have two courses: A and B.')), // the wrap-up
    ]);

    $events = iterator_to_array((new TextGenerationLoop(guardedGateway()))->stream(
        'inv-loop',
        GatewayFactory::provider(),
        'test/model',
        'be helpful',
        [new UserMessage('what courses do I have?')],
        [lookupTool()],
        null,
        new TextGenerationOptions(maxSteps: 2),
    ), preserve_keys: false);

    $bodies = sentBodies();
    $end = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($bodies)->toHaveCount(3)
        ->and($bodies[0])->toHaveKey('tools')
        ->and($bodies[1])->not->toHaveKey('tools')
        ->and($bodies[2])->not->toHaveKey('tools')
        // The SDK's persisted text: it joins each step's text with a
        // paragraph break, so the narration and the wrap-up read as two paragraphs.
        ->and(TextDelta::combine($events))->toBe("لنبحث أولاً عن مقرراتك.\n\nYou have two courses: A and B.")
        ->and(array_filter($events, fn ($e) => $e instanceof ToolResultEvent))->toHaveCount(1)
        ->and($end->usage->inputTokens)->toBe(3 + 7 + 10)
        ->and(TurnContext::flags())->toBe(['wrap_up' => 'blank_final']);
});

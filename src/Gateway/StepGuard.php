<?php

namespace Saad\AiKit\Gateway;

use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;

/**
 * The decisions behind a guaranteed final answer — pure functions over a
 * step's response and the messages that produced it, shared by the
 * gateway's streamed and non-streamed step overrides.
 *
 * WHY THE GATEWAY, AND WHY ONE STEP. laravel/ai's TextGenerationLoop owns the
 * step budget and the message history and exposes neither; what it does hand
 * the gateway is the StepContext (`isFinalStep`), the messages so far and
 * one StepResponse per step. That is enough: the gateway can run ONE extra
 * tool-less completion INSIDE the same step and merge it — the loop sees a
 * single step whose text now ends in a real answer, the SDK's persisted
 * assistant message (`TextDelta::combine()` over the yielded events) carries
 * the wrap-up text without a phantom user turn, `StreamEnd` accumulates its
 * usage, and its cost is captured per request like any other invocation.
 * Nothing above the gateway — mapper, runner, app job — changes.
 *
 * THE TWO SYMPTOMS (both seen in production threads):
 *  - `step_exhaustion`: the final step still ended in tool calls (only
 *    possible when `final_step.withhold_tools` is off, or the provider
 *    ignored the absence of tools). The loop would substitute its "maximum
 *    number of steps" sentinel and end; the user would see only the interim
 *    narration. The tool calls are KEPT so the loop still settles their
 *    chips, and the answer is appended.
 *  - `blank_final`: a step that followed tool activity finished with `stop`
 *    and empty content (DeepSeek does this after a tool-result step, and a
 *    stripped markup leak produces the same shape), so the visible reply is
 *    the pre-tool narration alone.
 * A first-step blank reply with no tools involved is left alone — that is
 * an ordinary empty answer for the app's own guard, not a turn that went
 * silent mid-work.
 */
class StepGuard
{
    public const STEP_EXHAUSTION = 'step_exhaustion';

    public const BLANK_FINAL = 'blank_final';

    /**
     * Why this step needs a wrap-up completion, or null when it stands.
     *
     * @param  array<int, mixed>  $messages  the messages the step was generated from
     * @param  array<string, mixed>  $wrapUp  the `ai-kit.chat.wrap_up` section
     */
    public static function wrapUpReason(
        StepResponse $step,
        array $messages,
        StepContext $stepContext,
        bool $offeredTools,
        array $wrapUp,
        bool $leaked = false,
    ): ?string {
        if ($step->pendingApprovals !== [] || $step->structured !== null) {
            return null;
        }

        if ($step->toolCalls !== [] && $step->finishReason === FinishReason::ToolCalls) {
            return $stepContext->isFinalStep && $offeredTools && ($wrapUp['on_exhaustion'] ?? true)
                ? static::STEP_EXHAUSTION
                : null;
        }

        if (! ($wrapUp['on_blank_final'] ?? true)) {
            return null;
        }

        if (trim($step->text) !== '' || $step->finishReason === FinishReason::Continue) {
            return null;
        }

        return static::followsToolActivity($messages) || $leaked ? static::BLANK_FINAL : null;
    }

    /**
     * Whether the step was generated right after tool results — the shape
     * of a turn that did work and then went quiet.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function followsToolActivity(array $messages): bool
    {
        $last = $messages === [] ? null : $messages[array_key_last($messages)];

        return $last instanceof ToolResultMessage;
    }

    /**
     * The history the wrap-up completion runs over: the step's messages, the
     * step's own visible text as an assistant turn when it said anything
     * (never its tool calls — an assistant `tool_calls` message with no
     * results after it is invalid on every Chat Completions API), and the
     * instruction as the closing user message.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, mixed>
     */
    public static function wrapUpMessages(array $messages, StepResponse $step, string $instruction): array
    {
        if (trim($step->text) !== '') {
            $messages[] = new AssistantMessage($step->text);
        }

        $messages[] = new UserMessage($instruction);

        return $messages;
    }

    /**
     * A wrap-up runs as its own final, tool-less step.
     */
    public static function wrapUpContext(StepContext $stepContext): StepContext
    {
        return new StepContext(
            stepNumber: $stepContext->stepNumber,
            isFinalStep: true,
            continuationToken: $stepContext->continuationToken,
        );
    }

    /**
     * The text that separates a step's narration from the appended answer —
     * empty when the step said nothing, so a blank step's wrap-up IS the text.
     */
    public static function separator(StepResponse $step): string
    {
        return trim($step->text) === '' ? '' : "\n\n";
    }

    /**
     * Fold a leak retry into the step it replaces. The retry's tool calls,
     * finish reason and leak facts win outright; usage is summed because
     * both requests were billed. The first attempt's clean narration is kept
     * only on the streamed path (`$keepFirstText`), where it already reached
     * the client and the SDK's combined text must match the wire.
     */
    public static function mergeRetry(StepResponse $first, StepResponse $retry, bool $keepFirstText): StepResponse
    {
        $merged = new InspectedStepResponse(
            text: $keepFirstText ? $first->text.$retry->text : $retry->text,
            toolCalls: $retry->toolCalls,
            finishReason: $retry->finishReason,
            usage: $first->usage->add($retry->usage),
            meta: $retry->meta,
            structured: $retry->structured,
            continuationToken: $retry->continuationToken,
            providerContentBlocks: $retry->providerContentBlocks,
            pendingApprovals: $retry->pendingApprovals,
        );

        if ($retry instanceof InspectedStepResponse) {
            $merged->inspected($retry->markupLeaked, $retry->leakedMarkup, $retry->providerName);
        }

        return $merged->withRawResponse($retry->raw);
    }

    /**
     * Fold the wrap-up completion into the step: text appended, usage summed,
     * the step's tool calls and finish reason preserved so the loop's
     * handling of them (sentinel tool results on an exhausted step) is
     * unchanged. A blank step takes the wrap-up's finish reason outright.
     */
    public static function merge(StepResponse $step, StepResponse $wrapUp): StepResponse
    {
        $text = trim($step->text) === ''
            ? $wrapUp->text
            : $step->text.static::separator($step).$wrapUp->text;

        $merged = new InspectedStepResponse(
            text: $text,
            toolCalls: $step->toolCalls,
            finishReason: $step->toolCalls !== [] ? $step->finishReason : $wrapUp->finishReason,
            usage: $step->usage->add($wrapUp->usage),
            meta: $step->meta,
            structured: $step->structured,
            continuationToken: $wrapUp->continuationToken ?? $step->continuationToken,
            providerContentBlocks: $step->providerContentBlocks,
            pendingApprovals: $step->pendingApprovals,
        );

        if ($step instanceof InspectedStepResponse) {
            $merged->inspected($step->markupLeaked, $step->leakedMarkup, $step->providerName);
        }

        return $merged->withRawResponse($wrapUp->raw ?? $step->raw);
    }
}

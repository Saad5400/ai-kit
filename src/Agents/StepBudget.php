<?php

namespace Saad\AiKit\Agents;

/**
 * The fleet's recommended step budget for a chat agent, read from
 * `ai-kit.chat.max_steps` so the number is decided once in the kit and an
 * app's agent only has to defer to it:
 *
 *     public function maxSteps(): int
 *     {
 *         return StepBudget::default();
 *     }
 *
 * (laravel/ai resolves a `maxSteps()` method ahead of the `#[MaxSteps]`
 * attribute.) A step is one model invocation. The gateway always sends the
 * LAST step without tools plus the answer-now nudge, so the budget reads as
 * "tool rounds + 1" and a turn cannot end on an unexecuted tool call; the
 * step guard's wrap-up covers the model going silent inside that budget.
 */
class StepBudget
{
    public const FALLBACK = 12;

    public static function default(): int
    {
        $configured = (int) config('ai-kit.chat.max_steps', static::FALLBACK);

        return max(2, $configured);
    }
}

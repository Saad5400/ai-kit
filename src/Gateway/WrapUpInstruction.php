<?php

namespace Saad\AiKit\Gateway;

use Closure;
use Illuminate\Support\Facades\Lang;

/**
 * The model-facing nudge the wrap-up completion appends as its last user
 * message: "you have no tool calls left — tell the user what you did, what
 * happened and what remains, in their language".
 *
 * Resolution order: an app closure registered with {@see using()} (receives
 * the wrap-up reason, returns the text — the seam for a per-locale or
 * per-agent instruction), else `ai-kit.chat.wrap_up.instruction` (a literal
 * string, or a translation key when one exists under it), else the
 * gateway's `final_step.message`, which says the same thing bilingually.
 */
class WrapUpInstruction
{
    protected static ?Closure $resolver = null;

    /**
     * Register an app-level resolver: `fn (string $reason): string`.
     */
    public static function using(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * @param  array<string, mixed>  $chat  the `ai-kit.chat` section
     * @param  array<string, mixed>  $gateway  the `ai-kit.gateway` section
     */
    public static function resolve(string $reason, array $chat, array $gateway): string
    {
        if (static::$resolver !== null) {
            $text = (string) (static::$resolver)($reason);

            if ($text !== '') {
                return $text;
            }
        }

        $configured = $chat['wrap_up']['instruction'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return Lang::has($configured) ? (string) __($configured) : $configured;
        }

        $fallback = $gateway['final_step']['message'] ?? null;

        return is_string($fallback) && $fallback !== '' ? $fallback : static::DEFAULT;
    }

    public const DEFAULT = 'لم يتبقَّ لديك استدعاءات أدوات في هذا الدور. أجب المستخدم الآن نصاً: ما الذي فعلته، وماذا حدث، وما الذي لم يُنجز بعد، وبلغته. '
        .'You have no tool calls left this turn. Reply to the user now, in their language: say what you did, what happened, and what remains.';
}

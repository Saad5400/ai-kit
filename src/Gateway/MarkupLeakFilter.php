<?php

namespace Saad\AiKit\Gateway;

use Saad\AiKit\Streaming\TextTransformer;

/**
 * A streaming sanitizer for provider tool-call markup that leaked into the
 * text channel.
 *
 * THE FAILURE. A model that wants to call a tool sometimes emits its own
 * tool-call grammar as plain content — DeepSeek's `<｜DSML｜invoke name="…">`
 * blocks are the case seen on staging — because the provider serving it did
 * not parse that grammar into structured `tool_calls`. Left alone, the
 * teacher watches raw markup stream into the reply and no tool runs.
 *
 * THE RULE. Once a configured marker appears, everything from it to the end
 * of the step is swallowed: text after a leaked tool-call block is the model
 * narrating results it never received. What was removed is kept in
 * {@see removed()} so the gateway can try to salvage the intended call
 * ({@see DsmlToolCallParser}) before it retries the step.
 *
 * ACROSS DELTA BOUNDARIES. Deltas arrive a few characters at a time, so a
 * marker may straddle two of them. A tail that is a proper prefix of any
 * marker (`<｜DS`) is held back until the next delta resolves it — released
 * unchanged when it turns out to be ordinary text (`<b>`), swallowed when it
 * completes a marker. Text containing no `<` at all is passed through
 * byte-for-byte, so a normal reply's deltas are untouched.
 */
class MarkupLeakFilter implements TextTransformer
{
    /**
     * The markers shipped as the kit default. Both the fullwidth `｜` DeepSeek
     * V4 emits and the ASCII degradations seen in the wild are listed; the
     * generic Hermes/Qwen/Anthropic-style tags cover other model families.
     *
     * @var list<string>
     */
    public const DEFAULT_PATTERNS = [
        '<｜DSML｜',
        '<|DSML|',
        '<||DSML||',
        '<｜tool▁calls▁begin｜>',
        '<｜tool▁call▁begin｜>',
        '<｜tool▁sep｜>',
        '<|tool_calls_begin|>',
        '<|tool_call_begin|>',
        '<|tool_sep|>',
        '<tool_call>',
        '<|tool_call|>',
        '<function_calls>',
        '<invoke name=',
    ];

    protected string $held = '';

    protected bool $inMarkup = false;

    protected string $removed = '';

    /**
     * @param  list<string>  $patterns
     */
    public function __construct(protected array $patterns = self::DEFAULT_PATTERNS)
    {
        $this->patterns = array_values(array_filter(
            array_map(strval(...), $patterns),
            fn (string $pattern): bool => $pattern !== '',
        ));
    }

    /**
     * Build a filter from the `ai-kit.gateway.markup_leak` config section; a
     * disabled section yields a filter that passes everything through.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        if (($config['enabled'] ?? true) === false) {
            return new self([]);
        }

        return new self($config['patterns'] ?? self::DEFAULT_PATTERNS);
    }

    public function push(string $delta): string
    {
        if ($delta === '') {
            return '';
        }

        if ($this->inMarkup) {
            $this->removed .= $delta;

            return '';
        }

        if ($this->patterns === []) {
            return $delta;
        }

        $buffer = $this->held.$delta;
        $this->held = '';

        // Fast path: nothing that could open a marker.
        if (! str_contains($buffer, '<')) {
            return $buffer;
        }

        $at = $this->firstMarkerAt($buffer);

        if ($at !== null) {
            $this->inMarkup = true;
            $this->removed .= substr($buffer, $at);

            return substr($buffer, 0, $at);
        }

        $hold = $this->holdFrom($buffer);

        if ($hold === null) {
            return $buffer;
        }

        $this->held = substr($buffer, $hold);

        return substr($buffer, 0, $hold);
    }

    public function flush(): string
    {
        $tail = $this->held;
        $this->held = '';

        return $tail;
    }

    /**
     * Whether a marker was seen in this step's text.
     */
    public function leaked(): bool
    {
        return $this->inMarkup;
    }

    /**
     * Everything swallowed from the first marker on — the raw block a
     * salvage pass parses.
     */
    public function removed(): string
    {
        return $this->removed;
    }

    /**
     * Byte offset of the earliest complete marker in the buffer, or null.
     */
    protected function firstMarkerAt(string $buffer): ?int
    {
        $earliest = null;

        foreach ($this->patterns as $pattern) {
            $at = strpos($buffer, $pattern);

            if ($at !== false && ($earliest === null || $at < $earliest)) {
                $earliest = $at;
            }
        }

        return $earliest;
    }

    /**
     * Byte offset from which the buffer's tail is a proper prefix of some
     * marker — the text that must be held until the next delta — or null
     * when the tail cannot be the start of a marker.
     */
    protected function holdFrom(string $buffer): ?int
    {
        $offset = 0;

        while (($at = strpos($buffer, '<', $offset)) !== false) {
            $tail = substr($buffer, $at);

            foreach ($this->patterns as $pattern) {
                if (strlen($tail) < strlen($pattern) && str_starts_with($pattern, $tail)) {
                    return $at;
                }
            }

            $offset = $at + 1;
        }

        return null;
    }
}

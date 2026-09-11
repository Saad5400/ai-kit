<?php

namespace Saad\AiKit\Gateway;

use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * Salvages the tool calls a DeepSeek model spelled out in its DSML grammar
 * when the provider failed to parse them.
 *
 * The grammar, as DeepSeek V4 emits it (the `｜` is U+FF5C; the ASCII
 * degradations `<|DSML|` and `<||DSML||` seen in the wild are normalized
 * first):
 *
 *     <｜DSML｜tool_calls>
 *     <｜DSML｜invoke name="get_weather">
 *     <｜DSML｜parameter name="city" string="true">Beijing</｜DSML｜parameter>
 *     <｜DSML｜parameter name="days">3</｜DSML｜parameter>
 *     </｜DSML｜invoke>
 *     </｜DSML｜tool_calls>
 *
 * Only INTACT invoke blocks are salvaged — one whose closing tag never
 * arrived is not a call the model finished making — and the outer
 * `tool_calls` wrapper is optional, so the "orphan invoke" form (start token
 * missing, `invoke` appearing mid-text) parses too. A parameter flagged
 * `string="true"` is taken verbatim; any other value is decoded as JSON,
 * falling back to the raw text when it is not JSON. Running the salvaged
 * call is the caller's decision: the parser does not know which tools were
 * offered.
 */
class DsmlToolCallParser
{
    protected const MARK = '<｜DSML｜';

    protected const CLOSE = '</｜DSML｜';

    /**
     * @return list<ToolCall>|null null when no intact invoke block exists
     */
    public static function parse(string $markup): ?array
    {
        $markup = static::normalize($markup);

        if (! str_contains($markup, static::MARK.'invoke')) {
            return null;
        }

        $mark = preg_quote(static::MARK, '/');
        $close = preg_quote(static::CLOSE, '/');

        if (! preg_match_all(
            '/'.$mark.'invoke\s+name="([^"]+)"\s*>(.*?)'.$close.'invoke\s*>/su',
            $markup,
            $invokes,
            PREG_SET_ORDER,
        )) {
            return null;
        }

        $calls = [];

        foreach ($invokes as [, $name, $body]) {
            $arguments = [];

            if (preg_match_all(
                '/'.$mark.'parameter\s+name="([^"]+)"((?:\s+[a-z_]+="[^"]*")*)\s*>(.*?)'.$close.'parameter\s*>/su',
                $body,
                $parameters,
                PREG_SET_ORDER,
            )) {
                foreach ($parameters as [, $parameter, $attributes, $value]) {
                    $arguments[$parameter] = static::value($value, str_contains($attributes, 'string="true"'));
                }
            }

            $id = 'salvaged_'.Str::lower(Str::random(12));

            $calls[] = new ToolCall($id, trim($name), $arguments, $id);
        }

        return $calls === [] ? null : $calls;
    }

    /**
     * Fold the ASCII degradations onto the canonical fullwidth marker.
     */
    protected static function normalize(string $markup): string
    {
        return str_replace(
            ['<||DSML||', '</||DSML||', '<|DSML|', '</|DSML|'],
            [static::MARK, static::CLOSE, static::MARK, static::CLOSE],
            $markup,
        );
    }

    protected static function value(string $raw, bool $isString): mixed
    {
        $raw = trim($raw);

        if ($isString) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}

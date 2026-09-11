<?php

namespace Saad\AiKit\Bench\Support;

use Illuminate\Support\Str;

/**
 * Tool names arrive two ways — the class basename an app writes in a
 * scenario (`UpsertCourse`) and the snake name the model called
 * (`upsert_course`) — so every comparison normalizes both sides here.
 */
final class ToolName
{
    public static function normalize(string $name): string
    {
        $basename = Str::afterLast($name, '\\');

        return Str::snake(str_replace(['-', ' '], '_', $basename));
    }

    /**
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public static function normalizeAll(iterable $names): array
    {
        $out = [];

        foreach ($names as $name) {
            $out[] = self::normalize((string) $name);
        }

        return $out;
    }
}

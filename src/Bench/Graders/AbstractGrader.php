<?php

namespace Saad\AiKit\Bench\Graders;

use Illuminate\Support\Str;
use Saad\AiKit\Bench\Grader;
use Saad\AiKit\Bench\Verdict;

abstract class AbstractGrader implements Grader
{
    public function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function pass(string $message = '', array $evidence = []): Verdict
    {
        return Verdict::pass($this->name(), $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function fail(string $message = '', array $evidence = []): Verdict
    {
        return Verdict::fail($this->name(), $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function warn(string $message = '', array $evidence = []): Verdict
    {
        return Verdict::warn($this->name(), $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function skip(string $message = '', array $evidence = []): Verdict
    {
        return Verdict::skip($this->name(), $message, $evidence);
    }

    /**
     * A bench config value, safe to call outside a booted container.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return config("ai-kit.bench.{$key}", $default);
    }
}

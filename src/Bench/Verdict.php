<?php

namespace Saad\AiKit\Bench;

use JsonSerializable;

final class Verdict implements JsonSerializable
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const SKIP = 'skip';

    /**
     * @param  string  $status  pass|fail|warn|skip
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $grader,
        public string $status,
        public string $message = '',
        public array $evidence = [],
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function pass(string $grader, string $message = '', array $evidence = []): self
    {
        return new self($grader, self::PASS, $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function fail(string $grader, string $message = '', array $evidence = []): self
    {
        return new self($grader, self::FAIL, $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function warn(string $grader, string $message = '', array $evidence = []): self
    {
        return new self($grader, self::WARN, $message, $evidence);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function skip(string $grader, string $message = '', array $evidence = []): self
    {
        return new self($grader, self::SKIP, $message, $evidence);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    public function passed(): bool
    {
        return $this->status === self::PASS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'grader' => $this->grader,
            'status' => $this->status,
            'message' => $this->message,
            'evidence' => $this->evidence,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

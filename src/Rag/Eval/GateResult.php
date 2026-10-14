<?php

namespace Saad\AiKit\Rag\Eval;

/**
 * A gate verdict. `refused` means the comparison itself is invalid (the
 * baseline was measured on different data) — neither a pass nor a fail.
 */
final class GateResult
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public readonly array $failures = [],
        public readonly ?string $refused = null,
    ) {}

    public function passed(): bool
    {
        return $this->refused === null && $this->failures === [];
    }
}

<?php

namespace Saad\AiKit\Tests\Support;

use Saad\AiKit\Rag\Search\Confidence;
use Saad\AiKit\Rag\Search\RetrievedHit;

final class RetrievedHitStub implements RetrievedHit
{
    public function __construct(
        public readonly string $key,
        public readonly Confidence $confidence = Confidence::High,
        public readonly string $body = 'passage text',
        public readonly string $headline = '',
    ) {}

    public static function make(string $key, string $confidence = 'high', ?string $body = null): self
    {
        return new self($key, Confidence::from($confidence), $body ?? "text of {$key}", "· ref {$key}");
    }

    public function key(): string
    {
        return $this->key;
    }

    public function confidence(): Confidence
    {
        return $this->confidence;
    }

    public function headline(): string
    {
        return $this->headline;
    }

    public function render(bool $brief): string
    {
        return $brief ? $this->body : $this->body.' (full)';
    }
}

<?php

namespace Saad\AiKit\Attachments;

/**
 * One routing decision for one attachment. `text` carries the free local
 * extraction; `vision` says "hand the bytes to a vision/OCR model" (the
 * kit never makes that call — the app owns its vision agent and its
 * metering); `skip` means the file contributes nothing (unknown format, or
 * a known one that yielded no text).
 */
final class Extraction
{
    public const ROUTE_TEXT = 'text';

    public const ROUTE_VISION = 'vision';

    public const ROUTE_SKIP = 'skip';

    private function __construct(
        public readonly string $route,
        public readonly ?string $text = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(self::ROUTE_TEXT, $text);
    }

    public static function vision(): self
    {
        return new self(self::ROUTE_VISION);
    }

    public static function skip(): self
    {
        return new self(self::ROUTE_SKIP);
    }

    public function isText(): bool
    {
        return $this->route === self::ROUTE_TEXT;
    }

    public function isVision(): bool
    {
        return $this->route === self::ROUTE_VISION;
    }

    public function isSkip(): bool
    {
        return $this->route === self::ROUTE_SKIP;
    }
}

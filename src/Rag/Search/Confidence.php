<?php

namespace Saad\AiKit\Rag\Search;

/**
 * How sure a retrieval stage is that a hit answers the query. A query is
 * "confident" when any of its hits is at least {@see Medium}; callers say
 * "no confident match" instead of presenting a Low hit as an answer.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}

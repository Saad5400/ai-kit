<?php

namespace Saad\AiKit\Rag\Glossary;

/**
 * A glossary held in memory — a platform vocabulary decoded from JSON, a test
 * fixture, or terms the app already loaded.
 */
final class ArrayGlossarySource implements GlossarySource
{
    /**
     * @param  list<GlossaryTerm>  $terms
     */
    public function __construct(private readonly array $terms) {}

    /**
     * From `[{term, alternatives?, source?}, …]` — the shape a JSON
     * vocabulary file decodes to.
     *
     * @param  list<array{term: string, alternatives?: list<string>, source?: string|null}>  $rows
     */
    public static function fromArray(array $rows): self
    {
        return new self(array_map(GlossaryTerm::fromArray(...), array_values($rows)));
    }

    public function terms(): iterable
    {
        return $this->terms;
    }
}

<?php

namespace Saad\AiKit\Rag\Glossary;

/**
 * Where an app's glossary DATA comes from — a per-course term table, a
 * platform vocabulary file, a set of fuzzy matches the app looked up for this
 * query. The kit owns the mechanism ({@see GlossaryExpander}); the app owns
 * the data and its scope (which courses are in reach is decided when the app
 * builds its source, not here).
 */
interface GlossarySource
{
    /**
     * @return iterable<GlossaryTerm>
     */
    public function terms(): iterable;
}

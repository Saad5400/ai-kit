<?php

namespace Saad\AiKit\Rag\Search;

/**
 * One search result as a bulk search tool prints it. The app's hit type
 * (a lesson, a video moment, a file chunk…) implements this; the kit only
 * dedupes, budgets and numbers.
 */
interface RetrievedHit
{
    /**
     * Stable identity across queries (e.g. `moment:qLbXNaxR@154622`): two
     * queries returning the same key returned the same thing, and it is
     * printed once.
     */
    public function key(): string;

    public function confidence(): Confidence;

    /**
     * The rest of the passage's header line, after `Passage N ` — a single
     * line (e.g. `[start_ms=154622] — lesson "…" https://…?t=154 · ref …`).
     */
    public function headline(): string;

    /** The passage body; `$brief` asks for the short form. */
    public function render(bool $brief): string;
}

<?php

namespace Saad\AiKit\Rag\Search;

/**
 * The results of ONE query inside a bulk search, best first.
 *
 * `searchedAlso` lists what the engine searched besides the query as typed
 * (glossary alternatives, a keyboard-layout fix); `note` carries anything
 * the model should know about how the search ran ("widened from the lecture
 * to the whole course"). `confident` overrides the default rule (any hit at
 * least Medium) when the engine decided it itself.
 */
final class HitGroup
{
    /**
     * @param  list<RetrievedHit>  $hits
     * @param  list<string>  $searchedAlso
     */
    public function __construct(
        public readonly string $query,
        public readonly array $hits = [],
        public readonly array $searchedAlso = [],
        public readonly ?string $note = null,
        public readonly ?bool $confident = null,
    ) {}

    public function isConfident(): bool
    {
        if ($this->confident !== null) {
            return $this->confident;
        }

        foreach ($this->hits as $hit) {
            if ($hit->confidence()->atLeast(Confidence::Medium)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same group with other hits (e.g. after an adjacency merge).
     *
     * @param  list<RetrievedHit>  $hits
     */
    public function withHits(array $hits): self
    {
        return new self($this->query, array_values($hits), $this->searchedAlso, $this->note, $this->confident);
    }
}

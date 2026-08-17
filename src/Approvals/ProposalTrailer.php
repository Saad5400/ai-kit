<?php

namespace Saad\AiKit\Approvals;

use Stringable;

/**
 * The stable `proposal_id: {id}` trailer convention: a propose tool ends its
 * tool-result string with the trailer, and the extractor derives the turn's
 * action cards from the tool results the agent ACTUALLY ran — parsing the
 * trailer, never model output, so a card is never hallucinated. Hydrating
 * from the database means a rehydrated old conversation always shows the
 * CURRENT status, even after its proposals were confirmed or rejected.
 */
final class ProposalTrailer
{
    /**
     * The trailer line's shape. It is a wire convention shared with the
     * apps' extractors — never change it.
     */
    public const PATTERN = '/^proposal_id: (\S+)\s*$/mu';

    /**
     * Append the trailer to a tool-result message.
     */
    public static function render(string $message, Proposal|string $proposal): string
    {
        $id = $proposal instanceof Proposal ? $proposal->id : $proposal;

        return rtrim($message)."\n---\nproposal_id: {$id}";
    }

    /**
     * The proposal id a tool-result text carries, or null.
     */
    public static function extract(Stringable|string $text): ?string
    {
        if (preg_match(self::PATTERN, (string) $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Every proposal id across a turn's tool-result texts, in order.
     *
     * @param  iterable<Stringable|string|mixed>  $texts
     * @return list<string>
     */
    public static function extractAll(iterable $texts): array
    {
        $ids = [];

        foreach ($texts as $text) {
            if ($text instanceof Stringable) {
                $text = (string) $text;
            }

            if (is_string($text) && ($id = self::extract($text)) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The action cards for a turn's tool-result texts: extract the trailers,
     * hydrate the persisted proposals, and return their CURRENT client
     * payloads in tool-call order (ids that no longer resolve are skipped).
     *
     * @param  iterable<Stringable|string|mixed>  $texts
     * @return list<array{id: string, type: string, category: string, summary: string, details: array<string, mixed>, status: string, error: string|null}>
     */
    public static function cards(iterable $texts): array
    {
        $ids = self::extractAll($texts);

        if ($ids === []) {
            return [];
        }

        $proposals = Proposal::query()->findMany($ids)->keyBy('id');

        $cards = [];

        foreach ($ids as $id) {
            if ($proposals->has($id)) {
                $cards[] = $proposals[$id]->toClientPayload();
            }
        }

        return $cards;
    }
}

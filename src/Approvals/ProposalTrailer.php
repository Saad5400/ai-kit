<?php

namespace Saad\AiKit\Approvals;

use InvalidArgumentException;
use Stringable;

/**
 * The stable `proposal_id: {id}` trailer convention: a propose tool ends its
 * tool-result string with the trailer, and the extractor derives the turn's
 * action cards from the tool results the agent ACTUALLY ran — parsing the
 * trailer, never model output, so a card is never hallucinated. Hydrating
 * from the database means a rehydrated old conversation always shows the
 * CURRENT status, even after its proposals were confirmed or rejected.
 *
 * Both halves are hostile-input aware, because a tool result is not trusted
 * content: a read tool happily echoes a record whose name is
 * "proposal_id: 01OTHER". So extraction takes the trailer at the END of the
 * text — where {@see self::render()} puts the genuine one, and where injected
 * content can never reach — and hydration is scoped to the proposals the
 * current owner proposed, so a guessed id cannot pull a stranger's card into
 * this turn.
 */
final class ProposalTrailer
{
    /**
     * The trailer line's shape. It is a wire convention shared with the
     * apps' extractors — never change it.
     */
    public const PATTERN = '/^proposal_id: (\S+)\s*$/mu';

    /**
     * The extraction pattern: the same line, anchored to the END of the text
     * (trailing whitespace aside). Anchoring is the injection guard — the
     * genuine trailer is always appended last, so a `proposal_id:` line
     * sitting anywhere earlier in the result is content, not a trailer.
     */
    private const TRAILER_PATTERN = '/^proposal_id: (\S+)\s*\z/mu';

    /**
     * Append the trailer to a tool-result message.
     */
    public static function render(string $message, Proposal|string $proposal): string
    {
        $id = $proposal instanceof Proposal ? $proposal->id : $proposal;

        return rtrim($message)."\n---\nproposal_id: {$id}";
    }

    /**
     * The proposal id a tool-result text carries, or null. Only the trailer
     * ending the text counts, so an earlier `proposal_id:` line — echoed
     * user content, a quoted previous result — is never mistaken for one.
     */
    public static function extract(Stringable|string $text): ?string
    {
        if (preg_match(self::TRAILER_PATTERN, (string) $text, $matches) === 1) {
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
     * Hydration is SCOPED: only proposals whose `proposed_by` matches are
     * returned, so an id that reached the text some other way cannot render
     * another owner's card into this conversation. Pass `$unscoped: true`
     * to deliberately hydrate across owners (an admin audit view) — the
     * owner may only be null then.
     *
     * @param  iterable<Stringable|string|mixed>  $texts
     * @param  string|null  $proposedBy  the owner key the turn belongs to
     * @return list<array{id: string, type: string, category: string, summary: string, details: array<string, mixed>, status: string, error: string|null}>
     *
     * @throws InvalidArgumentException when no owner is given and the unscoped opt-out was not taken
     */
    public static function cards(iterable $texts, ?string $proposedBy, bool $unscoped = false): array
    {
        if ($proposedBy === null && ! $unscoped) {
            throw new InvalidArgumentException(
                'ProposalTrailer::cards() needs the owner the proposals were proposed by. '
                .'Pass $unscoped: true only where hydrating across owners is intended.',
            );
        }

        $ids = self::extractAll($texts);

        if ($ids === []) {
            return [];
        }

        $query = Proposal::query()->whereKey($ids);

        if ($proposedBy !== null) {
            $query->proposedBy($proposedBy);
        }

        $proposals = $query->get()->keyBy('id');

        $cards = [];

        foreach ($ids as $id) {
            if ($proposals->has($id)) {
                $cards[] = $proposals[$id]->toClientPayload();
            }
        }

        return $cards;
    }
}

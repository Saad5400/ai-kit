<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\Str;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Exceptions\UnknownActionException;

/**
 * A single write the assistant wants to perform: the action type, a human
 * title, the executor input, and human-readable preview lines for the plan
 * card. `destructive`, `undoable` and `typedConfirm` are ALWAYS derived
 * server-side from the registered {@see Contracts\ProposableAction} — a
 * model-supplied flag is never trusted (that is why tools build these
 * through {@see ProposalBag::propose()} / {@see PlanBuilder::step()} rather
 * than constructing them from raw tool arguments).
 *
 * Round-trips through arrays so a plan can travel through a cache/turn
 * buffer and back for the confirm turn.
 */
final class ProposedWrite
{
    /**
     * @param  array<string, mixed>  $input  the action's input for this write
     * @param  list<string>  $preview  human-readable lines for the plan card
     * @param  string|null  $draftRef  same-turn handle minted for a create (e.g.
     *                                 "new_record_1") so a child proposed later
     *                                 in the same reply can reference the
     *                                 not-yet-persisted parent; resolved to
     *                                 the real id at execute time by the app.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $title,
        public readonly array $input,
        public readonly array $preview = [],
        public readonly bool $destructive = false,
        public readonly bool $undoable = true,
        public readonly ?string $typedConfirm = null,
        public readonly bool $createsRecord = false,
        public ?string $draftRef = null,
    ) {}

    public static function freshId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * Build a write with its risk flags derived from the REGISTERED action.
     * The single place that derivation happens — {@see ProposalBag::propose()}
     * and {@see PlanBuilder::step()} both come through here, so the two entry
     * points into the plan cannot drift apart on what counts as destructive.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $preview
     *
     * @throws UnknownActionException when no action is registered for $type
     */
    public static function derive(
        ActionRegistry $registry,
        string $type,
        string $title,
        array $input,
        array $preview = [],
        bool $createsRecord = false,
        mixed $actor = null,
    ): self {
        $action = $registry->get($type) ?? throw new UnknownActionException($type);

        return new self(
            id: self::freshId(),
            type: $type,
            title: $title,
            input: $input,
            preview: $preview,
            destructive: $action->destructive(),
            undoable: $action->undoable(),
            typedConfirm: $action->typedConfirmPhrase($input, $actor),
            createsRecord: $createsRecord,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: (string) $data['type'],
            title: (string) $data['title'],
            input: (array) ($data['input'] ?? []),
            preview: array_values(array_map(strval(...), (array) ($data['preview'] ?? []))),
            destructive: (bool) ($data['destructive'] ?? false),
            undoable: (bool) ($data['undoable'] ?? true),
            typedConfirm: isset($data['typed_confirm']) ? (string) $data['typed_confirm'] : null,
            createsRecord: (bool) ($data['creates_record'] ?? false),
            draftRef: isset($data['draft_ref']) ? (string) $data['draft_ref'] : null,
        );
    }

    /**
     * The full storable form (round-trips via {@see self::fromArray()}).
     *
     * @return array{id: string, type: string, title: string, input: array<string, mixed>, preview: list<string>, destructive: bool, undoable: bool, typed_confirm: string|null, creates_record: bool, draft_ref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'input' => $this->input,
            'preview' => $this->preview,
            'destructive' => $this->destructive,
            'undoable' => $this->undoable,
            'typed_confirm' => $this->typedConfirm,
            'creates_record' => $this->createsRecord,
            'draft_ref' => $this->draftRef,
        ];
    }

    /**
     * The canonical client step shape for the plan card. `typed_confirm` is
     * present only when this step demands the retype gate.
     *
     * @return array{id: string, type: string, title: string, preview: list<string>, destructive: bool, typed_confirm?: string}
     */
    public function toStep(): array
    {
        $step = [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'preview' => $this->preview,
            'destructive' => $this->destructive,
        ];

        if ($this->typedConfirm !== null) {
            $step['typed_confirm'] = $this->typedConfirm;
        }

        return $step;
    }
}

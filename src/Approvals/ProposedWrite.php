<?php

namespace Saad\AiKit\Approvals;

use Illuminate\Support\Str;

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

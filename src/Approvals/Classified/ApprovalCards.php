<?php

namespace Saad\AiKit\Approvals\Classified;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Saad\AiKit\Approvals\Contracts\Classified;
use Saad\AiKit\Streaming\StreamEventMapper;

/**
 * Renders a paused turn's `pendingApprovals` into the canonical client
 * cards, with every trust-bearing field resolved SERVER-side from the
 * agent's own tool instances — the model contributes only the arguments,
 * which are exactly what a resume will execute (preview == execution).
 *
 * Card shapes (owner decision #4):
 * - `question {id, question, options?}` — an {@see AskUser} pause; answered,
 *   not approved. `options` carries the model's suggested answers when it
 *   proposed any.
 * - `approval {id, tool, title, destructive, undoable, editable,
 *   arguments, fields, preview, reason}` — destructive renders as a
 *   one-click card (`editable: false`); payload writes render as an editable
 *   form built from `fields` and resubmitted as an edit decision through the
 *   same schema-validated tool path.
 *
 * `fields` is the FORM SCHEMA (widget, label, options, editability, current
 * value) per argument, from the tool's {@see ClassifiedTool::fields()} spec
 * or inferred from the value. It exists because a client that only receives
 * `arguments` has to guess, and guessing turned every confirm dialog into a
 * row of editable text boxes — including the ids the write addresses. The
 * flat `arguments` map stays on the card for one version, for clients that
 * have not adopted `fields` yet.
 *
 * Streaming: {@see attachTo()} hooks the mapper's ToolApprovalRequest and
 * emits one wire event per card. Non-streaming: {@see cards()} builds the
 * same payloads from a response's `pendingApprovals`.
 *
 * SECURITY: `fields` describes what the client SHOULD render; it is not
 * what makes an edit safe. Run every edit decision through
 * {@see guardEdits()} — or hand {@see editGuard()} to
 * {@see ResumeDecisions::fromClient()}, which applies it for you — before
 * resuming the turn.
 */
class ApprovalCards
{
    /** @var list<Tool> */
    protected array $tools;

    /**
     * @param  iterable<Tool>  $tools  the agent's tools() — the classification source
     */
    public function __construct(iterable $tools)
    {
        $this->tools = collect($tools)->values()->all();
    }

    /**
     * @param  iterable<PendingApproval>  $pendingApprovals
     * @return list<array<string, mixed>>
     */
    public function cards(iterable $pendingApprovals): array
    {
        return collect($pendingApprovals)
            ->map(fn (PendingApproval $approval): array => $this->card($approval))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function card(PendingApproval $approval): array
    {
        $tool = $this->findTool($approval->tool);

        if ($tool instanceof AskUser) {
            $card = [
                'kind' => 'question',
                'id' => $approval->id,
                'question' => (string) ($approval->arguments['question'] ?? $approval->reason ?? ''),
            ];

            $options = AskUser::options($approval->arguments);

            return $options === [] ? $card : [...$card, 'options' => $options];
        }

        $capability = $tool instanceof Classified ? $tool->capability() : null;
        $destructive = $capability?->effect === Effect::Destructive;
        $request = new Request($approval->arguments, $approval->id);
        $editable = $capability !== null && ! $destructive;

        return [
            'kind' => 'approval',
            'id' => $approval->id,
            'tool' => $approval->tool,
            'title' => $tool instanceof ClassifiedTool
                ? $tool->title($request)
                : Str::headline($approval->tool),
            // Unclassified Approvable tools fail safe: treated as
            // destructive (one-click, no auto-anything) until classified.
            'destructive' => $capability === null || $destructive,
            'undoable' => $capability?->undoable ?? false,
            'editable' => $editable,
            'arguments' => $approval->arguments,
            'fields' => collect($this->fields($approval, $editable))
                ->map(fn (Field $field, string $name): array => $field->toArray(
                    $approval->arguments[$name] ?? null,
                ))
                ->values()
                ->all(),
            'preview' => $tool instanceof ClassifiedTool ? $tool->preview($request) : [],
            'reason' => $approval->reason,
        ];
    }

    /**
     * The form schema for one pending call, keyed by argument name in render
     * order: every argument the model actually sent, then any extra field the
     * tool declared as an optional input.
     *
     * `$editable` is the card's own flag — a one-click card locks every field
     * regardless of what the tool declared, because the whole point of the
     * one-click tier is that nothing about the call is negotiable.
     *
     * @return array<string, Field>
     */
    public function fields(PendingApproval $approval, ?bool $editable = null): array
    {
        $tool = $this->findTool($approval->tool);
        $spec = $tool instanceof ClassifiedTool || $tool instanceof AskUser ? $tool->fields() : [];
        $editable ??= $this->editable($tool);

        $fields = [];

        foreach ($approval->arguments as $name => $value) {
            $name = (string) $name;

            $fields[$name] = array_key_exists($name, $spec)
                ? Field::fromSpec($name, $spec[$name], $value)
                : Field::infer($name, $value);
        }

        // Declared fields the model left out still render, so a tool can
        // offer an input the model never filled.
        foreach ($spec as $name => $declared) {
            $name = (string) $name;

            $fields[$name] ??= Field::fromSpec($name, $declared);
        }

        return $editable
            ? $fields
            : array_map(fn (Field $field): Field => $field->locked(), $fields);
    }

    /**
     * The SAFE argument set for an edit decision: the user's values for the
     * fields the card offered as editable, the ORIGINAL pending values for
     * everything readonly or hidden (silently restored, not rejected — a
     * stale client should not cost the user their turn).
     *
     * This is the server-side half of owner decision #4's editable form. The
     * card's `editable` flags live in the browser, where a determined user
     * owns them; an edited `*_id` that reaches the tool repoints the write at
     * a record the user was never shown, which is why restoring here — not
     * validating in the client — is what makes "preview == execution" true.
     *
     * Argument keys the card never carried are a protocol error, not a stale
     * flag, so they throw: nothing legitimate invents an argument name, and
     * silently dropping one would hide a client bug.
     *
     * @param  array<string, mixed>  $editedArguments
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function guardEdits(PendingApproval $approval, array $editedArguments): array
    {
        $fields = $this->fields($approval);
        $unknown = array_diff(array_keys($editedArguments), array_keys($fields));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Edit for tool call [%s] introduces unknown argument(s) [%s].',
                $approval->id,
                implode(', ', $unknown),
            ));
        }

        $safe = $approval->arguments;

        foreach ($fields as $name => $field) {
            if (! $field->editable || ! array_key_exists($name, $editedArguments)) {
                continue;
            }

            $safe[$name] = $field->cast($editedArguments[$name], $approval->arguments[$name] ?? null);
        }

        return $safe;
    }

    /**
     * {@see guardEdits()} as a guard closure for
     * {@see ResumeDecisions::fromClient()} — the seam where the guard
     * provably runs before the resumed turn reaches the tool:
     *
     *     $pending = (new StoredApprovals)->pending($conversationId);
     *     $decisions = ResumeDecisions::fromClient($input, $cards->editGuard($pending));
     *
     * `$pendingApprovals` is the SERVER's pending set (the stored pause
     * markers), never the client's echo of it — that is what makes the
     * original values trustworthy. A decision for an id that is not pending
     * throws.
     *
     * @param  iterable<PendingApproval>  $pendingApprovals
     * @return Closure(string, array<string, mixed>): array<string, mixed>
     */
    public function editGuard(iterable $pendingApprovals): Closure
    {
        $pending = collect($pendingApprovals)->keyBy(fn (PendingApproval $approval): string => $approval->id);

        return function (string $id, array $editedArguments) use ($pending): array {
            $approval = $pending->get($id);

            if (! $approval instanceof PendingApproval) {
                throw new InvalidArgumentException("Tool call [{$id}] is not awaiting a decision.");
            }

            return $this->guardEdits($approval, $editedArguments);
        };
    }

    /**
     * Hook the mapper: each pause emits one `question` or `approval` wire
     * event per card, then the turn's `done` follows as usual.
     */
    public function attachTo(StreamEventMapper $mapper): StreamEventMapper
    {
        return $mapper->on(
            ToolApprovalRequest::class,
            function (ToolApprovalRequest $event, callable $emit): void {
                foreach ($event->pendingApprovals as $approval) {
                    $card = $this->card($approval);

                    $emit($card['kind'] === 'question' ? 'question' : 'approval', $card);
                }
            },
        );
    }

    /**
     * Whether this tool's card offers editable inputs at all: an
     * {@see AskUser} question is answerable by definition; a classified write
     * is editable unless it is destructive; anything unclassified fails safe.
     */
    protected function editable(?Tool $tool): bool
    {
        if ($tool instanceof AskUser) {
            return true;
        }

        $capability = $tool instanceof Classified ? $tool->capability() : null;

        return $capability !== null && $capability->effect !== Effect::Destructive;
    }

    protected function findTool(string $name): ?Tool
    {
        foreach ($this->tools as $tool) {
            if (ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }
}

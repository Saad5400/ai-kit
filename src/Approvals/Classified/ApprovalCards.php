<?php

namespace Saad\AiKit\Approvals\Classified;

use Closure;
use Illuminate\Support\Facades\App;
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
    /**
     * How much of one value {@see text()} prints before it elides. Long
     * enough for a chapter name or a one-line summary, short enough that a
     * lesson body cannot become the whole message.
     */
    protected const VALUE_LIMIT = 160;

    /** The row marker {@see text()} leads detail lines with. */
    protected const BULLET = '• ';

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
     * One card as PLAIN TEXT, for a surface that has no form to render into:
     * Telegram, SMS, a plain-text email, a log line. Same payload as
     * {@see card()}, same server-derived trust — title, the tool's human
     * label, a warning line when the call is destructive, the argument rows,
     * the tool's preview lines, then the reason — flattened to one string,
     * one fact per line.
     *
     * PLAIN TEXT MEANS PLAIN TEXT. No HTML, no Markdown, no entity escaping:
     * the values here are model-supplied arguments and app-supplied copy, so
     * the transport that adds markup is the one that must escape them
     * (Telegram's HTML parse mode included). Emitting `<b>` here would make
     * every caller un-escape it first.
     *
     * `$locale` decides BOTH halves of the card's language: the kit's own
     * copy, and the app copy the tool resolves while rendering — a queued
     * Telegram turn runs under the worker's locale, not the chat's, so the
     * whole rendering happens with the translator switched (and switched
     * back, exceptions included).
     *
     * What is deliberately NOT printed: hidden fields (they travel with the
     * call and are never shown — {@see Field::hidden()}), readonly identity
     * fields (`track_id: v6oPvGqX` is the headline noise owner ruling #22
     * banished into a disclosure on the web card, and a text surface has no
     * disclosure), arguments the model left out (a form row for an unset
     * optional input is an affordance; a text line saying "unset" is not),
     * and a preview line that only repeats the title (the same dedupe
     * `js/core/cards.ts` does).
     */
    public function text(PendingApproval $approval, string $locale): string
    {
        return $this->inLocale($locale, function () use ($approval): string {
            $card = $this->card($approval);

            $lines = $card['kind'] === 'question'
                ? $this->questionLines($card)
                : $this->approvalLines($card);

            return implode("\n", array_values(array_filter(
                array_map(trim(...), $lines),
                fn (string $line): bool => $line !== '',
            )));
        });
    }

    /**
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    protected function approvalLines(array $card): array
    {
        // The title is app copy with model arguments interpolated into it, so
        // it is flattened and capped like any other value: a newline in an
        // argument would otherwise fake a row.
        $title = $this->value($card['title']);

        $tool = Str::headline((string) $card['tool']);

        $lines = [$title];

        // A tool that wrote no title() of its own gets the humanized tool
        // name AS the title — printing it again under "Tool:" is the same
        // duplicate the preview dedupe exists to kill (owner ruling #22).
        if (! $this->sameText($tool, $title)) {
            $lines[] = (string) __('ai-kit::approvals.text.tool', ['tool' => $tool]);
        }

        if ($card['destructive'] === true) {
            $lines[] = (string) __('ai-kit::approvals.text.destructive');
        }

        foreach ($this->fieldLines($card) as $line) {
            $lines[] = $line;
        }

        foreach ($this->previewLines($card, $title) as $line) {
            $lines[] = $line;
        }

        if (($card['reason'] ?? null) !== null && trim((string) $card['reason']) !== '') {
            $lines[] = (string) __('ai-kit::approvals.text.reason', [
                'reason' => $this->value($card['reason']),
            ]);
        }

        return $lines;
    }

    /**
     * An {@see AskUser} pause as text: the question, then the model's
     * suggested answers as bullets when it proposed any. There is nothing to
     * approve here, so there is no tool line and no warning.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    protected function questionLines(array $card): array
    {
        return [
            $this->value($card['question']),
            ...array_map(
                fn (mixed $option): string => $this->bullet($this->value($option)),
                array_values($card['options'] ?? []),
            ),
        ];
    }

    /**
     * `• Label: value` per visible, filled, non-identity argument — the
     * card's own `fields` rows, so a tool's declared label and order decide
     * what the reader meets first, exactly as on the web card.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    protected function fieldLines(array $card): array
    {
        $lines = [];

        /** @var array<string, mixed> $field */
        foreach ($card['fields'] as $field) {
            $value = $field['value'] ?? null;

            if ($field['widget'] === FieldWidget::Hidden->value
                || $this->identity($field)
                || $value === null
                || $value === ''
                || $value === []) {
                continue;
            }

            $label = ($field['label'] ?? null) === null || $field['label'] === ''
                ? $this->humanize((string) $field['name'])
                : (string) $field['label'];

            $lines[] = $this->bullet($label.': '.$this->value($value));
        }

        return $lines;
    }

    /**
     * The tool's preview rows as bullets, with the title dropped out of them.
     * Both wire shapes are accepted — a list renders in order, a
     * `{key: value}` map renders as humanized `Label: value` rows — mirroring
     * `previewLines()` in `js/core/cards.ts` so the two surfaces print the
     * same thing.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    protected function previewLines(array $card, string $title): array
    {
        $preview = $card['preview'] ?? [];

        if (! is_array($preview)) {
            $preview = [$preview];
        }

        $lines = [];

        foreach ($preview as $key => $row) {
            $text = $this->value($row);

            if ($text === '') {
                continue;
            }

            if (is_string($key)) {
                $text = $this->humanize($key).': '.$text;
            }

            if ($this->sameText($text, $title)) {
                continue;
            }

            $lines[] = $this->bullet($text);
        }

        return $lines;
    }

    /**
     * One value as display text: a localized yes/no for a boolean, JSON for a
     * structure, the value itself otherwise — always on ONE line (a newline
     * inside a value would fake a new row) and always capped, because the
     * argument that carries a whole lesson body would otherwise be the whole
     * message.
     */
    protected function value(mixed $value): string
    {
        if (is_bool($value)) {
            return (string) __('ai-kit::approvals.text.'.($value ? 'yes' : 'no'));
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return Str::limit($text, self::VALUE_LIMIT, '…');
    }

    protected function bullet(string $text): string
    {
        return self::BULLET.$text;
    }

    /**
     * A raw argument name as a human label — the PHP mirror of
     * `humanizeFieldName()` in `js/core/fields.ts`, down to dropping a
     * trailing `id`/`uuid` token when something is left to name: `track_id`
     * reads as `Track`, which is the record, where `Track id` reads as a
     * database column. The last resort, not the plan: a tool that wants its
     * arguments labelled declares them, in the conversation's language.
     */
    protected function humanize(string $name): string
    {
        $words = array_values(array_filter(
            preg_split('/[\s_\-.]+/u', (string) preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $name)) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        $words = array_map(mb_strtolower(...), $words);

        if (count($words) > 1 && preg_match('/^(id|ids|uuid|uuids)$/', end($words)) === 1) {
            array_pop($words);
        }

        $text = implode(' ', $words);

        return $text === '' ? $name : Str::ucfirst($text);
    }

    /**
     * Whether a rendered field row addresses the record rather than describes
     * it — the mirror of `isIdentityField()` in `js/core/fields.ts`, and
     * readonly for the same reason: an id the tool deliberately opened is a
     * control the reader is meant to see.
     *
     * @param  array<string, mixed>  $field
     */
    protected function identity(array $field): bool
    {
        return $field['widget'] === FieldWidget::Readonly->value
            && $field['editable'] === false
            && preg_match('/(^|_)(id|uuid)s?$|([a-z\d])(Id|Uuid)s?$/', (string) $field['name']) === 1;
    }

    /** Whitespace- and case-insensitive sameness — for dedupe only. */
    protected function sameText(string $a, string $b): bool
    {
        $normalize = fn (string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));

        return $normalize($a) === $normalize($b);
    }

    /**
     * Run a rendering with the translator on `$locale`, restoring the
     * previous one whatever happens — so `text($approval, 'ar')` is Arabic
     * even inside an English-locale queue worker, and leaves nothing behind.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $render
     * @return TReturn
     */
    protected function inLocale(string $locale, Closure $render): mixed
    {
        $previous = App::getLocale();

        App::setLocale($locale);

        try {
            return $render();
        } finally {
            App::setLocale($previous);
        }
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

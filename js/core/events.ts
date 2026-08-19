/**
 * The canonical wire contract, as TypeScript.
 *
 * Every event here is emitted by `Saad\AiKit\Streaming\StreamEventMapper`
 * (inline) or replayed out of `TurnBuffer` (resumable) — the two paths emit
 * the same sequence for the same turn, so a client written against these
 * types works on either. Frames are `event: NAME\ndata: {json}\n\n`,
 * optionally led by `id: SEQ` on the buffered path.
 *
 * TERMINAL CONTRACT: a turn ends with exactly one of `done` or `error`.
 * `error` is terminal — no `done` ever follows it.
 *
 * Apps may emit extension events of their own (`step`, `segment`, `plan`,
 * ...) through `on()` / `beforeDone()` hooks; those are outside this union
 * by design — see {@link isAiKitEvent} for narrowing a raw dispatch.
 */

/** Model text. Deltas concatenate; the client owns the accumulation. */
export type DeltaPayload = {
    text: string
}

/**
 * A chunk of the model's thinking. There are no start/end wire events: a
 * client opens its thinking block on the first `reasoning` and closes it on
 * the first following `delta`, `tool`, `done` or `error`. A turn may reopen
 * the block if the model thinks again between tool calls.
 */
export type ReasoningPayload = {
    text: string
}

/**
 * Tool progress, correlated by `id` — `running` when the call starts,
 * `done` when it returns. Arguments and results are deliberately absent:
 * these apps are public-facing and tool payloads carry retrieved records.
 * An app that wants more hooks `ToolCall` server-side and emits its own
 * extension event.
 */
export type ToolPayload = {
    id: string
    name: string
    status: 'running' | 'done'
    /** Present on `status: 'done'` only. */
    successful?: boolean
}

/**
 * How ONE approval argument should be rendered, decided server-side by
 * `Saad\AiKit\Approvals\Classified\FieldWidget`.
 *
 * `hidden` travels with the call but is never shown; `readonly` renders as a
 * label and a value, never as an input. `markdown` and `code` are long-form
 * text — a monospace editor by default, substitutable per app.
 */
export type ApprovalCardFieldWidget =
    | 'hidden'
    | 'readonly'
    | 'text'
    | 'number'
    | 'boolean'
    | 'select'
    | 'textarea'
    | 'markdown'
    | 'code'

export type ApprovalCardFieldOption = {
    value: unknown
    label: string
}

/**
 * One field of an approval form. The server builds these so the client stops
 * inferring input types from raw JSON — the failure mode that rendered every
 * confirm dialog as a row of editable text boxes, ids included.
 *
 * `editable` is NOT a security boundary on its own: it says what to render.
 * The server re-derives it on the way back in
 * (`ApprovalCards::guardEdits()`), restoring readonly and hidden values from
 * the pending call, so a tampered form cannot repoint the write.
 */
export type ApprovalCardField = {
    name: string
    widget: ApprovalCardFieldWidget
    editable: boolean
    /** Falls back to `name` when the tool declared no label. */
    label: string | null
    /** `select` only; null otherwise. */
    options: ApprovalCardFieldOption[] | null
    placeholder: string | null
    /** The pending call's current value; null for an optional field the model left out. */
    value: unknown
}

/**
 * A paused turn waiting on the user, rendered from
 * `Saad\AiKit\Approvals\Classified\ApprovalCards`. Every trust-bearing
 * field is resolved server-side; `arguments` is exactly what a resume will
 * execute (preview == execution).
 *
 * `destructive` cards are one-click (`editable: false`); the rest render as
 * a form prefilled from `arguments` and resubmitted as an edit decision.
 *
 * `id` is the tool call's id: a paused call emits `tool {status:
 * 'running'}` first and its card second, so fold the chip into the card
 * rather than leaving it spinning. The `done` for that id arrives on the
 * resumed turn's stream.
 */
export type ApprovalPayload = {
    kind: 'approval'
    id: string
    tool: string
    title: string
    destructive: boolean
    undoable: boolean
    editable: boolean
    /**
     * The flat argument map. Superseded by `fields` (which carries the same
     * values plus how to render them) and kept for one version so a client
     * that has not adopted the form schema keeps working.
     *
     * @deprecated render `fields` instead.
     */
    arguments: Record<string, unknown>
    /** The form schema, in render order. */
    fields: ApprovalCardField[]
    /** Tool-shaped summary rows; `[]` when the tool renders no preview. */
    preview: unknown[] | Record<string, unknown>
    reason: string | null
}

/**
 * An `AskUser` pause — answered, not approved.
 *
 * `options` are 2–4 suggested answers the model proposed, present only when
 * it proposed any. They are suggestions: the card shows them as one-tap chips
 * NEXT TO a free-text input, and the answer that resumes the turn is whatever
 * the user sent either way.
 */
export type QuestionPayload = {
    kind: 'question'
    id: string
    question: string
    options?: string[]
}

/**
 * Post-stream sources, emitted from a `beforeDone` hook. The item shape is
 * the app's — only `items` is contractual.
 */
export type CitationsPayload = {
    items: Array<Record<string, unknown>>
}

/**
 * The terminal success event. Its payload is assembled per app through
 * `doneUsing()`; `conversation_id` is the one field all three send.
 */
export type DonePayload = {
    conversation_id?: string
} & Record<string, unknown>

/**
 * The terminal failure event. `message` is already display-ready — apps
 * replace the provider's text with a localized line via `onError()`.
 */
export type ErrorPayload = {
    message: string
}

export type DeltaEvent = { event: 'delta'; data: DeltaPayload }
export type ReasoningEvent = { event: 'reasoning'; data: ReasoningPayload }
export type ToolEvent = { event: 'tool'; data: ToolPayload }
export type ApprovalEvent = { event: 'approval'; data: ApprovalPayload }
export type QuestionEvent = { event: 'question'; data: QuestionPayload }
export type CitationsEvent = { event: 'citations'; data: CitationsPayload }
export type DoneEvent = { event: 'done'; data: DonePayload }
export type ErrorEvent = { event: 'error'; data: ErrorPayload }

export type AiKitSseEvent =
    | DeltaEvent
    | ReasoningEvent
    | ToolEvent
    | ApprovalEvent
    | QuestionEvent
    | CitationsEvent
    | DoneEvent
    | ErrorEvent

export type AiKitEventName = AiKitSseEvent['event']

/** A pause card, whichever kind arrived. */
export type AiKitCard = ApprovalPayload | QuestionPayload

const NAMES: readonly AiKitEventName[] = [
    'delta',
    'reasoning',
    'tool',
    'approval',
    'question',
    'citations',
    'done',
    'error',
]

/**
 * Whether a dispatched event name belongs to the contract — narrowing it
 * so a `switch` over the rest is exhaustive, and letting a reader pass an
 * app's own extension events through untouched.
 */
export function isAiKitEvent(event: string): event is AiKitEventName {
    return (NAMES as readonly string[]).includes(event)
}

/** Whether this event ends the turn — the client tears the stream down. */
export function isTerminal(event: string): boolean {
    return event === 'done' || event === 'error'
}

/**
 * Whether this event closes an open thinking block. See
 * {@link ReasoningPayload} for the bracket-free reasoning contract.
 */
export function closesReasoning(event: string): boolean {
    return event === 'delta' || event === 'tool' || isTerminal(event)
}

/**
 * The `arguments` of an `{action: 'edit'}` decision, built from a card's field
 * schema and the user's in-progress edits: every EDITABLE field's current
 * value, and nothing else.
 *
 * Readonly and hidden fields are left out rather than echoed back — the server
 * restores them from the pending call either way
 * (`ApprovalCards::guardEdits()`), so sending them only invites a client to
 * think it owns them.
 *
 * @param edits values keyed by field name; a field the user has not touched
 * falls back to the value the card arrived with.
 */
export function editableArguments(
    fields: readonly ApprovalCardField[],
    edits: Record<string, unknown> = {},
): Record<string, unknown> {
    return Object.fromEntries(
        fields
            .filter((field) => field.editable)
            .map((field) => [field.name, edits[field.name] ?? field.value ?? null]),
    )
}

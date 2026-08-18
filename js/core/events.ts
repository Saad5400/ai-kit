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
 * A paused turn waiting on the user, rendered from
 * `Saad\AiKit\Approvals\Classified\ApprovalCards`. Every trust-bearing
 * field is resolved server-side; `arguments` is exactly what a resume will
 * execute (preview == execution).
 *
 * `destructive` cards are one-click (`editable: false`); the rest render as
 * a form prefilled from `arguments` and resubmitted as an edit decision.
 */
export type ApprovalPayload = {
    kind: 'approval'
    id: string
    tool: string
    title: string
    destructive: boolean
    undoable: boolean
    editable: boolean
    arguments: Record<string, unknown>
    /** Tool-shaped summary rows; `[]` when the tool renders no preview. */
    preview: unknown[] | Record<string, unknown>
    reason: string | null
}

/** An `AskUser` pause — answered, not approved. */
export type QuestionPayload = {
    kind: 'question'
    id: string
    question: string
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
 * Narrow a raw `(event, data)` dispatch to the contract, so a reader can
 * pass app extension events through untouched.
 */
export function isAiKitEvent(event: string, data: unknown): data is AiKitSseEvent['data'] {
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

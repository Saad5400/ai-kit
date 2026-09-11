/**
 * The ordered segment timeline: the client-side model of one assistant turn.
 *
 * WHY THIS EXISTS. The wire events already arrive in true chronological order
 * (`StreamEventMapper` emits reasoning and tool events at the point they
 * occur, ahead of text a transformer is holding back). Every app that
 * hand-rolled its own accumulation still ended up with the thinking block
 * pinned to the TOP of the message, because a single `reasoning` string plus
 * a single `text` string cannot express "talked, thought, called a tool,
 * talked again, thought again". This reducer keeps a LIST of segments in
 * arrival order instead, so the rendered turn matches what the model
 * actually did.
 *
 * REACTIVITY. `push()` mutates the array it was given, in place — it never
 * reassigns `segments` and never replaces a segment object wholesale unless
 * the segment's own type changed. Pass your framework's reactive array IN, so
 * every mutation goes through its proxy:
 *
 *     // Vue
 *     const segments = reactive<Segment[]>([])
 *     const timeline = createTimeline(segments)
 *
 *     // Svelte 5
 *     let segments = $state<Segment[]>([])
 *     const timeline = createTimeline(segments)
 *
 * Then feed it the reader's dispatch verbatim — `readSseStream` is
 * callback-based, and its handler's arguments are exactly `push()`'s:
 *
 *     await readSseStream(response, (event, data) => {
 *         timeline.push(event, data)
 *     })
 *
 * Reading the raw array back out of `timeline.segments` in a template works
 * too, but only because it IS the proxy you passed in — do not hand it a
 * plain array and then wrap the result, or the mutations happen behind the
 * proxy's back and nothing re-renders.
 */

import type { AiKitCard, ToolPayload, ToolProgress } from './events'

/** A run of model text. Consecutive `delta` events merge into one. */
export type TextSegment = {
    type: 'text'
    text: string
}

/** A run of model thinking. Consecutive `reasoning` events merge into one. */
export type ThinkingSegment = {
    type: 'thinking'
    text: string
}

/**
 * One tool call, at the position where it STARTED. A `done` event updates
 * this segment in place rather than appending — the chip must not jump to the
 * end of the turn when the call returns. `progress` is whatever the latest
 * `running` frame reported (see {@link ToolProgress}); it is gone once the
 * call is `done`.
 */
export type ToolSegment = {
    type: 'tool'
    id: string
    name: string
    status: ToolPayload['status']
    successful?: boolean
    progress?: ToolProgress
}

/**
 * A pause card (approval or question). Decision state is the app's — the
 * timeline only owns where the card sits in the turn.
 */
export type CardSegment = {
    type: 'card'
    card: AiKitCard
}

export type Segment = TextSegment | ThinkingSegment | ToolSegment | CardSegment

export type Timeline = {
    /**
     * Fold one wire event into the timeline. Unknown event names (`citations`,
     * `done`, `error`, an app's own extension events) are ignored, so a
     * caller can pass the whole dispatch through without filtering.
     */
    push(event: string, data: unknown): void
    /** The live segment list, in arrival order. Mutated in place. */
    segments: Segment[]
}

/**
 * @param segments the array to accumulate into — pass a reactive one (see the
 * module docblock). Defaults to a plain array, which is what the tests and
 * any non-reactive consumer want.
 */
export function createTimeline(segments: Segment[] = []): Timeline {
    const trailing = <T extends Segment['type']>(type: T): Extract<Segment, { type: T }> | null => {
        const last = segments[segments.length - 1]

        return last?.type === type ? (last as Extract<Segment, { type: T }>) : null
    }

    const appendText = (type: 'text' | 'thinking', text: string): void => {
        if (text === '') {
            return
        }

        const open = trailing(type)

        if (open === null) {
            segments.push(type === 'text' ? { type: 'text', text } : { type: 'thinking', text })

            return
        }

        open.text += text
    }

    const upsertTool = (payload: ToolPayload): void => {
        const at = segments.findIndex(
            (segment) => segment.type === 'tool' && segment.id === payload.id,
        )

        if (at === -1) {
            // The first frame for an id is the `running` one and carries the
            // name; an orphan progress frame (no name, nothing held) is still
            // kept rather than dropped, since its progress is what the user
            // wants to see.
            const fresh: ToolSegment = {
                type: 'tool',
                id: payload.id,
                name: payload.name ?? '',
                status: payload.status,
            }

            if (payload.successful !== undefined) {
                fresh.successful = payload.successful
            }

            if (payload.status === 'running' && payload.progress !== undefined) {
                fresh.progress = payload.progress
            }

            segments.push(fresh)

            return
        }

        // In place: the chip stays where the call started. Reading the element
        // out of the (possibly proxied) array hands back the reactive child,
        // so these writes are observed.
        //
        // The merge rules of the `tool` contract (see `ToolPayload`): an
        // absent `name` keeps the held one; `progress` is replaced wholesale
        // when sent, kept when a `running` frame omits it, and dropped on
        // `done` — a settled chip must never keep showing "12/40".
        const existing = segments[at] as ToolSegment

        if (payload.name !== undefined) {
            existing.name = payload.name
        }

        existing.status = payload.status

        if (payload.successful !== undefined) {
            existing.successful = payload.successful
        }

        if (payload.status === 'done') {
            delete existing.progress
        } else if (payload.progress !== undefined) {
            existing.progress = payload.progress
        }
    }

    const upsertCard = (card: AiKitCard): void => {
        // The fold rule of the v0.5.0 contract: a paused call emitted its
        // `tool running` chip first and its card second, under the SAME id.
        // The card replaces the chip in place, so the decision surface
        // appears where the call happened and no spinner is left running.
        const at = segments.findIndex(
            (segment) =>
                (segment.type === 'tool' && segment.id === card.id) ||
                (segment.type === 'card' && segment.card.id === card.id),
        )

        if (at === -1) {
            segments.push({ type: 'card', card })

            return
        }

        segments[at] = { type: 'card', card }
    }

    return {
        segments,
        push(event: string, data: unknown): void {
            switch (event) {
                case 'delta':
                    appendText('text', text(data))
                    break
                case 'reasoning':
                    appendText('thinking', text(data))
                    break
                case 'tool':
                    if (isTool(data)) {
                        upsertTool(data)
                    }
                    break
                case 'approval':
                case 'question':
                    if (isCard(data)) {
                        upsertCard(data)
                    }
                    break
            }
        },
    }
}

/**
 * A turn's thinking, tool calls and the narration between them, collapsed
 * into one disclosure. A `text` item here is narration — words the model
 * wrote BEFORE it went on to call a tool ("let me look up your courses
 * first") — never the reply; see {@link groupSegments}.
 */
export type ProcessGroup = {
    type: 'process'
    items: Array<ThinkingSegment | ToolSegment | TextSegment>
}

export type SegmentGroup = TextSegment | CardSegment | ProcessGroup

export type GroupOptions = {
    /**
     * Where text the model wrote before a later tool call goes.
     *
     * `'process'` (default): it is narration about the work — a step in the
     * process — and joins the disclosure, so the reply bubble holds only what
     * the model said once it was done. `'text'`: every text segment stays a
     * top-level bubble in its original position (the pre-v0.11 rendering).
     */
    narration?: 'process' | 'text'
}

/**
 * Collapse the timeline into render groups: consecutive `thinking` and `tool`
 * segments become ONE `process` group — a single "steps" disclosure rather
 * than a disclosure per thought — while `card` segments and the REPLY stay
 * top-level in their original positions.
 *
 * NARRATION IS PROCESS. A model working with tools talks while it works:
 * "لنبحث أولاً عن مقرراتك" and then a tool call, a result, and the answer.
 * Rendered as two reply bubbles, that turn reads as an assistant that
 * announced a search and then answered — or, when the turn went wrong, as an
 * assistant that announced a search and stopped. The narration was a step
 * in the process, so text that is FOLLOWED by a tool call anywhere later in
 * the turn joins the process group (opening one if none is open), and only
 * text with no tool call after it is the reply. The rule is over the whole
 * timeline, so while a turn streams a text segment is a reply until the
 * tool call that follows it arrives, then moves into the disclosure — the
 * one visible reflow, and the correct one. `options.narration: 'text'`
 * restores the old rendering.
 *
 * Cards are deliberately NOT swallowed into a process group: an approval card
 * is a decision surface the user has to reach, not a progress detail to hide
 * behind a summary. A card arriving BETWEEN two runs of thinking splits them
 * into two groups rather than joining either, so a pending card can never land
 * inside a collapsed disclosure — the guarantee owner ruling #22 asks for, and
 * it holds for a settled card too, whose outcome is the record of a decision
 * the user made rather than progress detail.
 *
 * THE OTHER HALF OF THAT GUARANTEE IS THE APP'S. This function hands the card
 * over as a top-level group; an app that renders that group INSIDE its own
 * thinking disclosure — or on the same surface, with no separation — buries it
 * again, which is what the prod screenshots showed. Render `card` groups as
 * siblings of the process disclosure, never as children of it.
 *
 * Consecutive text segments are NOT merged either, even though they could be:
 * text separated by thinking or a tool call is text the model wrote at two
 * different points, and flattening it back into one block is exactly the bug
 * this module exists to fix.
 *
 * Pure — call it from a Vue `computed` / Svelte `$derived` over
 * `timeline.segments`.
 */
export function groupSegments(segments: readonly Segment[], options: GroupOptions = {}): SegmentGroup[] {
    const groups: SegmentGroup[] = []
    const narrationInProcess = (options.narration ?? 'process') === 'process'

    // Index of the last tool segment: text before it is narration.
    let lastTool = -1

    if (narrationInProcess) {
        for (let index = segments.length - 1; index >= 0; index--) {
            if (segments[index].type === 'tool') {
                lastTool = index
                break
            }
        }
    }

    segments.forEach((segment, index) => {
        const isNarration = segment.type === 'text' && index < lastTool

        if (segment.type === 'card' || (segment.type === 'text' && !isNarration)) {
            groups.push(segment)

            return
        }

        const last = groups[groups.length - 1]

        if (last?.type === 'process') {
            last.items.push(segment)

            return
        }

        groups.push({ type: 'process', items: [segment] })
    })

    return groups
}

const text = (data: unknown): string => {
    const value = (data as { text?: unknown } | null)?.text

    return typeof value === 'string' ? value : ''
}

const isTool = (data: unknown): data is ToolPayload => {
    const payload = data as ToolPayload | null

    return (
        typeof payload?.id === 'string' &&
        (payload.name === undefined || typeof payload.name === 'string') &&
        (payload.status === 'running' || payload.status === 'done')
    )
}

const isCard = (data: unknown): data is AiKitCard =>
    typeof (data as AiKitCard | null)?.id === 'string'

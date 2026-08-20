/**
 * The resumable-turn reader: one long-running assistant turn, followed from
 * the durable `TurnBuffer` until it ends, across every connection drop.
 *
 * WHY THIS EXISTS. The buffered path (`GET …/turns/{turn}/stream?cursor=N`)
 * lets a client drop and come back — but only if the client does the
 * coming back. Catodemy's `chat-state` grew that logic app-side: a cursor
 * taken from each frame's `id:`, a reconnect ladder with exponential
 * backoff, a "turn expired" branch on 404, a silence watchdog behind the
 * composer's "still processing" line. It is transport, not chat, and
 * s-grade and uqucc would have rewritten it line for line. This is that
 * logic, once, with the app's concerns left to the app: building the URL
 * (locale prefix, route shape), folding events (the timeline), and what to
 * say when the turn is lost.
 *
 * THE LADDER. `cursor` is the last `id:` seen and is re-issued on EVERY
 * attempt, so the server replays only what this client has not folded. A
 * read ends one of three ways: the turn finished (a terminal frame —
 * `done` resolves and the helper stops), the server hit its own ceiling
 * (the 180 s hangup) and closed mid-turn, or the connection broke. The last
 * two are the same case here: count one failure, wait `backoffMs(n)`, try
 * again from `cursor`. Any frame that carries an `id` resets the count —
 * progress means the previous drop is forgiven, so a ten-minute turn that
 * hangs up every 180 s never exhausts its retries, while a dead tail that
 * only ever disconnects does, after `maxConsecutiveFailures`. A 404 is
 * final on the spot: the buffer is gone, there is nothing to resume.
 *
 * Only `stop()` — the user's stop, or a new turn replacing this one —
 * returns without reconnecting.
 */
import { isTerminal } from './events'
import { readSseStream } from './sse'

/**
 * Why the helper gave up on the turn.
 *
 *  - `expired`: the server answered 404 — the turn buffer is gone (TTL, or
 *    a turn id that never existed). Immediate; no retry.
 *  - `failed`: `maxConsecutiveFailures` drops in a row with no frame in
 *    between — the tail is dead, or the network is.
 *  - `gone`: reserved for a non-retryable HTTP status. Not emitted by this
 *    version — every non-404 non-ok response counts as a `failed` step, as
 *    catodemy's client always did — but handle it like `failed` so a later
 *    kit can start sending it without breaking you.
 */
export type ResumeLostReason = 'expired' | 'failed' | 'gone'

export type ResumeTurnOptions = {
    /**
     * Build the stream URL for a cursor — the app owns the route and its
     * locale prefix. Called on every attempt with the current cursor.
     */
    url: (cursor: number) => string
    /**
     * One frame: the event name, the parsed payload, and the buffer sequence
     * number from the frame's `id:` (null when the frame carried none). Feed
     * it to the timeline verbatim — terminal events included, the helper
     * stops on its own after dispatching one.
     */
    onEvent: (event: string, data: unknown, seq: number | null) => void
    /** The turn cannot be followed any further — see {@link ResumeLostReason}. */
    onLost: (reason: ResumeLostReason) => void
    /**
     * `silenceMs` passed with no frame at all — the "still processing" hook.
     * Fires ONCE per silence window; any frame re-arms it, so a turn that
     * goes quiet twice reports twice. The window starts when the helper does,
     * so a turn that never emits is reported too.
     */
    onSilence?: (silentMs: number) => void
    /** Injected for tests; defaults to the global `fetch`. */
    fetch?: typeof fetch
    /** Drops in a row before `onLost('failed')`. Default 8. */
    maxConsecutiveFailures?: number
    /**
     * Wait before reconnect attempt `n` (1-based). Default
     * `min(1000 · 2^(n−1), 8000)` — 1 s, 2 s, 4 s, then 8 s flat.
     */
    backoffMs?: (attempt: number) => number
    /** The silence window. Default 20 000. */
    silenceMs?: number
}

export type ResumeHandle = {
    /**
     * Abort the in-flight read and never reconnect. Idempotent; safe to call
     * from inside `onEvent`. Resolves `done`.
     */
    stop(): void
    /** The last buffer sequence number seen — what the next attempt resumes from. */
    readonly cursor: number
    /**
     * Settles once the helper has stopped for ANY reason — a terminal frame,
     * `onLost`, or `stop()`. Never rejects: failures arrive through `onLost`,
     * so a caller that only cares about "is it over" can await this alone.
     */
    readonly done: Promise<void>
}

const defaultBackoffMs = (attempt: number): number => Math.min(1000 * 2 ** (attempt - 1), 8000)

export function resumeTurn(options: ResumeTurnOptions): ResumeHandle {
    const {
        url,
        onEvent,
        onLost,
        onSilence,
        fetch: fetchImpl = globalThis.fetch,
        maxConsecutiveFailures = 8,
        backoffMs = defaultBackoffMs,
        silenceMs = 20_000,
    } = options

    let cursor = 0
    let failures = 0
    let finished = false
    let stopped = false
    let controller: AbortController | null = null
    let silenceTimer: ReturnType<typeof setTimeout> | null = null
    let backoffTimer: ReturnType<typeof setTimeout> | null = null
    let wake: (() => void) | null = null

    let resolveDone!: () => void
    const done = new Promise<void>((resolve) => {
        resolveDone = resolve
    })

    const clearSilence = (): void => {
        if (silenceTimer !== null) {
            clearTimeout(silenceTimer)
            silenceTimer = null
        }
    }

    /** One-shot: fires once, and only a frame arms it again. */
    const armSilence = (): void => {
        clearSilence()

        if (onSilence === undefined) {
            return
        }

        silenceTimer = setTimeout(() => {
            silenceTimer = null
            onSilence(silenceMs)
        }, silenceMs)
    }

    /** The helper is over, whichever way: tear down and settle `done`. */
    const finish = (): void => {
        stopped = true
        clearSilence()

        if (backoffTimer !== null) {
            clearTimeout(backoffTimer)
            backoffTimer = null
        }

        const current = controller
        controller = null
        current?.abort()

        wake?.()
        resolveDone()
    }

    const lose = (reason: ResumeLostReason): void => {
        if (stopped) {
            return
        }

        finish()
        onLost(reason)
    }

    const sleep = (ms: number): Promise<void> =>
        new Promise((resolve) => {
            wake = resolve
            backoffTimer = setTimeout(() => {
                backoffTimer = null
                resolve()
            }, ms)
        })

    const handleFrame = (event: string, data: unknown, id?: string): void => {
        let seq: number | null = null

        if (id !== undefined) {
            const parsed = Number.parseInt(id, 10)

            if (!Number.isNaN(parsed)) {
                seq = parsed
                cursor = parsed
                // Progress forgives the previous drops.
                failures = 0
            }
        }

        // Any frame means the job is alive: re-arm the silence window.
        armSilence()

        onEvent(event, data, seq)

        if (isTerminal(event)) {
            finished = true
            // Stop reading — the turn is over, whatever the body still holds.
            controller?.abort()
        }
    }

    const run = async (): Promise<void> => {
        while (!stopped) {
            const current = new AbortController()
            controller = current

            try {
                const response = await fetchImpl(url(cursor), {
                    headers: { Accept: 'text/event-stream' },
                    credentials: 'same-origin',
                    signal: current.signal,
                })

                if (response.status === 404) {
                    // The turn buffer expired — nothing to resume.
                    void response.body?.cancel().catch(() => {})
                    lose('expired')

                    return
                }

                if (!response.ok) {
                    void response.body?.cancel().catch(() => {})

                    throw new Error(`resumeTurn: the stream answered ${response.status}`)
                }

                await readSseStream(response, handleFrame, { signal: current.signal })
            } catch {
                // A rejected fetch, a non-ok status, or a read that broke
                // mid-body — all one kind of drop. An abort lands here too
                // when `stop()` raced the fetch; the check below sorts it.
            }

            if (stopped) {
                return
            }

            if (finished) {
                finish()

                return
            }

            // The server hung up mid-turn (its own ceiling, or the network):
            // climb the ladder, then resume from `cursor`.
            if (failures >= maxConsecutiveFailures) {
                lose('failed')

                return
            }

            failures++
            await sleep(backoffMs(failures))
        }
    }

    armSilence()
    void run()

    return {
        stop: finish,
        get cursor(): number {
            return cursor
        },
        done,
    }
}

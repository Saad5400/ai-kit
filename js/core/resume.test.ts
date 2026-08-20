/**
 * The reconnect ladder, exercised against a scripted `fetch`. Every case
 * runs under fake timers so the backoff and the silence window are
 * observed, not waited for.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resumeTurn } from './resume'
import type { ResumeTurnOptions } from './resume'

const frame = (event: string, data: unknown, id?: number) =>
    `${id === undefined ? '' : `id: ${id}\n`}event: ${event}\ndata: ${JSON.stringify(data)}\n\n`

/** A 200 whose body yields the chunks and then closes — a hangup if no terminal is in them. */
const body = (...chunks: string[]): Response => {
    const encoder = new TextEncoder()

    return new Response(
        new ReadableStream<Uint8Array>({
            start(controller) {
                for (const chunk of chunks) {
                    controller.enqueue(encoder.encode(chunk))
                }

                controller.close()
            },
        }),
        { status: 200 },
    )
}

/** A 200 whose body stays open until the test pushes into it or closes it. */
const openBody = () => {
    const encoder = new TextEncoder()
    let controller!: ReadableStreamDefaultController<Uint8Array>

    const response = new Response(
        new ReadableStream<Uint8Array>({
            start(c) {
                controller = c
            },
        }),
        { status: 200 },
    )

    return {
        response,
        push: (chunk: string) => controller.enqueue(encoder.encode(chunk)),
        close: () => controller.close(),
    }
}

type Step = Response | Error | (() => Response | Promise<Response>)

/**
 * A fetch that plays one step per call: a Response is returned, an Error
 * is thrown (network failure), a function is invoked. Records every URL
 * and the signal it was called with.
 */
const scriptedFetch = (steps: Step[]) => {
    const urls: string[] = []
    const signals: AbortSignal[] = []

    const impl = vi.fn(async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
        urls.push(String(input))
        signals.push(init!.signal!)

        const step = steps.shift()

        if (step === undefined) {
            throw new Error('scripted fetch ran out of steps')
        }

        if (step instanceof Error) {
            throw step
        }

        if (typeof step === 'function') {
            return step()
        }

        return step
    })

    return { impl, urls, signals }
}

type ScriptedFetch = ReturnType<typeof scriptedFetch>['impl']

const start = (fetchImpl: ScriptedFetch, overrides: Partial<ResumeTurnOptions> = {}) => {
    const events: Array<[string, unknown, number | null]> = []
    const onLost = vi.fn()
    const onSilence = vi.fn()

    const handle = resumeTurn({
        url: (cursor) => `/turns/t1/stream?cursor=${cursor}`,
        onEvent: (event, data, seq) => events.push([event, data, seq]),
        onLost,
        onSilence,
        fetch: fetchImpl,
        ...overrides,
    })

    return { handle, events, onLost, onSilence }
}

/** Let the stream machinery's microtasks run without moving the clock. */
const settle = () => vi.advanceTimersByTimeAsync(0)

beforeEach(() => {
    vi.useFakeTimers()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('resumeTurn', () => {
    it('reads a turn to its terminal, resolves done and stops', async () => {
        const fetch = scriptedFetch([
            body(frame('delta', { text: 'Hel' }, 1), frame('delta', { text: 'lo' }, 2), frame('done', { conversation_id: 'c9' }, 3)),
        ])
        const { handle, events, onLost } = start(fetch.impl)

        await settle()
        await handle.done

        expect(events).toEqual([
            ['delta', { text: 'Hel' }, 1],
            ['delta', { text: 'lo' }, 2],
            ['done', { conversation_id: 'c9' }, 3],
        ])
        expect(handle.cursor).toBe(3)
        expect(onLost).not.toHaveBeenCalled()
        expect(fetch.urls).toEqual(['/turns/t1/stream?cursor=0'])
    })

    it('treats error as terminal too — no reconnect follows it', async () => {
        const fetch = scriptedFetch([body(frame('error', { message: 'boom' }, 4))])
        const { handle, events, onLost } = start(fetch.impl)

        await settle()
        await handle.done
        await vi.advanceTimersByTimeAsync(10_000)

        expect(events.map((e) => e[0])).toEqual(['error'])
        expect(onLost).not.toHaveBeenCalled()
        expect(fetch.impl).toHaveBeenCalledTimes(1)
    })

    it('reconnects after a hangup, re-issuing the advanced cursor', async () => {
        const fetch = scriptedFetch([
            body(frame('delta', { text: 'a' }, 1), frame('delta', { text: 'b' }, 2)),
            body(frame('delta', { text: 'c' }, 3), frame('done', {}, 4)),
        ])
        const { handle, events } = start(fetch.impl)

        await settle()

        expect(fetch.urls).toEqual(['/turns/t1/stream?cursor=0'])
        expect(handle.cursor).toBe(2)

        // The hangup costs one backoff step (1 s) before the retry.
        await vi.advanceTimersByTimeAsync(999)
        expect(fetch.impl).toHaveBeenCalledTimes(1)

        await vi.advanceTimersByTimeAsync(1)
        await handle.done

        expect(fetch.urls).toEqual(['/turns/t1/stream?cursor=0', '/turns/t1/stream?cursor=2'])
        expect(events.map((e) => e[2])).toEqual([1, 2, 3, 4])
    })

    it('passes null for a frame with no id and keeps the cursor where it was', async () => {
        const fetch = scriptedFetch([body(frame('delta', { text: 'x' }, 7), frame('citations', { items: [] }), frame('done', {}))])
        const { handle, events } = start(fetch.impl)

        await settle()
        await handle.done

        expect(events.map((e) => e[2])).toEqual([7, null, null])
        expect(handle.cursor).toBe(7)
    })

    it('climbs the backoff ladder and gives up after maxConsecutiveFailures drops', async () => {
        const fetch = scriptedFetch(Array.from({ length: 9 }, () => new Error('ECONNREFUSED')))
        const { handle, onLost } = start(fetch.impl)

        await settle()
        expect(fetch.impl).toHaveBeenCalledTimes(1)

        // 1 s, 2 s, 4 s, then 8 s flat — eight retries in total.
        for (const wait of [1000, 2000, 4000, 8000, 8000, 8000, 8000, 8000]) {
            await vi.advanceTimersByTimeAsync(wait - 1)
            const before = fetch.impl.mock.calls.length
            await vi.advanceTimersByTimeAsync(1)
            expect(fetch.impl.mock.calls.length).toBe(before + 1)
        }

        await handle.done

        expect(fetch.impl).toHaveBeenCalledTimes(9)
        expect(onLost).toHaveBeenCalledExactlyOnceWith('failed')

        // Nothing further: no tenth attempt.
        await vi.advanceTimersByTimeAsync(60_000)
        expect(fetch.impl).toHaveBeenCalledTimes(9)
    })

    it('counts a non-ok response as one failure, not a loss', async () => {
        const fetch = scriptedFetch([new Response(null, { status: 502 }), body(frame('done', {}, 1))])
        const { handle, onLost } = start(fetch.impl)

        await settle()
        await vi.advanceTimersByTimeAsync(1000)
        await handle.done

        expect(fetch.impl).toHaveBeenCalledTimes(2)
        expect(onLost).not.toHaveBeenCalled()
    })

    it('resets the failure count when a frame with an id arrives', async () => {
        const steps: Step[] = []

        // Seven straight failures, then a frame, then seven more — never
        // eight in a row, so the turn survives to its terminal.
        for (let index = 0; index < 7; index++) steps.push(new Error('down'))
        steps.push(body(frame('delta', { text: 'alive' }, 1)))
        for (let index = 0; index < 7; index++) steps.push(new Error('down'))
        steps.push(body(frame('done', {}, 2)))

        const fetch = scriptedFetch(steps)
        const { handle, onLost, events } = start(fetch.impl, { backoffMs: () => 1 })

        await settle()
        await vi.advanceTimersByTimeAsync(100)
        await handle.done

        expect(fetch.impl).toHaveBeenCalledTimes(16)
        expect(onLost).not.toHaveBeenCalled()
        expect(events.map((e) => e[0])).toEqual(['delta', 'done'])
    })

    it('honours a custom backoff and failure cap', async () => {
        const backoffMs = vi.fn(() => 50)
        const fetch = scriptedFetch([new Error('a'), new Error('b'), new Error('c')])
        const { handle, onLost } = start(fetch.impl, { backoffMs, maxConsecutiveFailures: 2 })

        await settle()
        await vi.advanceTimersByTimeAsync(100)
        await handle.done

        expect(backoffMs.mock.calls).toEqual([[1], [2]])
        expect(fetch.impl).toHaveBeenCalledTimes(3)
        expect(onLost).toHaveBeenCalledExactlyOnceWith('failed')
    })

    it('reports expired on a 404 at once, with no retry', async () => {
        const fetch = scriptedFetch([new Response(null, { status: 404 })])
        const { handle, onLost } = start(fetch.impl)

        await settle()
        await handle.done
        await vi.advanceTimersByTimeAsync(60_000)

        expect(onLost).toHaveBeenCalledExactlyOnceWith('expired')
        expect(fetch.impl).toHaveBeenCalledTimes(1)
    })

    it('reports expired on a 404 met mid-ladder too', async () => {
        const fetch = scriptedFetch([new Error('down'), new Response(null, { status: 404 })])
        const { handle, onLost } = start(fetch.impl)

        await settle()
        await vi.advanceTimersByTimeAsync(1000)
        await handle.done

        expect(onLost).toHaveBeenCalledExactlyOnceWith('expired')
    })

    it('stop() aborts the in-flight read and never reconnects', async () => {
        const open = openBody()
        const fetch = scriptedFetch([open.response])
        const { handle, events, onLost } = start(fetch.impl)

        await settle()
        open.push(frame('delta', { text: 'partial' }, 1))
        await settle()

        expect(events).toHaveLength(1)
        expect(fetch.signals[0]!.aborted).toBe(false)

        handle.stop()

        expect(fetch.signals[0]!.aborted).toBe(true)

        await handle.done
        await vi.advanceTimersByTimeAsync(60_000)

        expect(fetch.impl).toHaveBeenCalledTimes(1)
        expect(onLost).not.toHaveBeenCalled()
        expect(handle.cursor).toBe(1)
    })

    it('stop() during the backoff wait cancels the pending reconnect', async () => {
        const fetch = scriptedFetch([new Error('down'), body(frame('done', {}, 1))])
        const { handle, onLost } = start(fetch.impl)

        await settle()
        await vi.advanceTimersByTimeAsync(500)

        handle.stop()

        await handle.done
        await vi.advanceTimersByTimeAsync(60_000)

        expect(fetch.impl).toHaveBeenCalledTimes(1)
        expect(onLost).not.toHaveBeenCalled()
    })

    it('stop() is idempotent and safe from inside onEvent', async () => {
        const fetch = scriptedFetch([body(frame('delta', { text: 'x' }, 1), frame('delta', { text: 'y' }, 2))])
        let handle!: ReturnType<typeof resumeTurn>
        const seen: string[] = []

        handle = resumeTurn({
            url: () => '/s',
            onEvent: (event) => {
                seen.push(event)
                handle.stop()
                handle.stop()
            },
            onLost: () => {},
            fetch: fetch.impl,
        })

        await settle()
        await handle.done
        await vi.advanceTimersByTimeAsync(60_000)

        expect(seen).toEqual(['delta'])
        expect(fetch.impl).toHaveBeenCalledTimes(1)
    })

    it('fires onSilence once per quiet window and re-arms on any frame', async () => {
        const open = openBody()
        const fetch = scriptedFetch([open.response])
        const { handle, onSilence } = start(fetch.impl)

        await settle()

        // Quiet from the start: the window runs from when the helper began.
        await vi.advanceTimersByTimeAsync(19_999)
        expect(onSilence).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)
        expect(onSilence).toHaveBeenCalledExactlyOnceWith(20_000)

        // One-shot: more silence does not repeat it…
        await vi.advanceTimersByTimeAsync(40_000)
        expect(onSilence).toHaveBeenCalledTimes(1)

        // …until a frame re-arms the window.
        open.push(frame('delta', { text: 'back' }, 1))
        await settle()
        await vi.advanceTimersByTimeAsync(19_999)
        expect(onSilence).toHaveBeenCalledTimes(1)

        await vi.advanceTimersByTimeAsync(1)
        expect(onSilence).toHaveBeenCalledTimes(2)

        // A frame in time pushes it back.
        open.push(frame('delta', { text: 'a' }, 2))
        await settle()
        await vi.advanceTimersByTimeAsync(15_000)
        open.push(frame('delta', { text: 'b' }, 3))
        await settle()
        await vi.advanceTimersByTimeAsync(15_000)
        expect(onSilence).toHaveBeenCalledTimes(2)

        handle.stop()
        await handle.done

        // Stopping clears the watchdog.
        await vi.advanceTimersByTimeAsync(60_000)
        expect(onSilence).toHaveBeenCalledTimes(2)
    })

    it('honours a custom silence window and stops the watchdog on a terminal', async () => {
        const open = openBody()
        const fetch = scriptedFetch([open.response])
        const { handle, onSilence } = start(fetch.impl, { silenceMs: 5000 })

        await settle()
        await vi.advanceTimersByTimeAsync(5000)
        expect(onSilence).toHaveBeenCalledExactlyOnceWith(5000)

        open.push(frame('done', {}, 1))
        await settle()
        await handle.done

        await vi.advanceTimersByTimeAsync(60_000)
        expect(onSilence).toHaveBeenCalledTimes(1)
    })

    it('sends the event-stream accept header with same-origin credentials', async () => {
        const fetch = scriptedFetch([body(frame('done', {}, 1))])
        const { handle } = start(fetch.impl)

        await settle()
        await handle.done

        const init = fetch.impl.mock.calls[0]![1] as RequestInit

        expect(init.headers).toEqual({ Accept: 'text/event-stream' })
        expect(init.credentials).toBe('same-origin')
    })
})

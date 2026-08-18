import { describe, expect, it, vi } from 'vitest'
import { readSseStream } from './sse'

/** A Response whose body yields the given chunks, one read at a time. */
function streamOf(...chunks: string[]): Response {
    const encoder = new TextEncoder()

    const body = new ReadableStream<Uint8Array>({
        start(controller) {
            for (const chunk of chunks) {
                controller.enqueue(encoder.encode(chunk))
            }

            controller.close()
        },
    })

    return new Response(body)
}

async function collect(...chunks: string[]) {
    const events: Array<[string, any, string | undefined]> = []

    await readSseStream(streamOf(...chunks), (event, data, id) => {
        events.push([event, data, id])
    })

    return events
}

const frame = (event: string, data: unknown, id?: number) =>
    `${id === undefined ? '' : `id: ${id}\n`}event: ${event}\ndata: ${JSON.stringify(data)}\n\n`

describe('readSseStream', () => {
    it('reads a whole turn and reports the sequence ids', async () => {
        const events = await collect(
            frame('delta', { text: 'Hel' }, 1),
            frame('delta', { text: 'lo' }, 2),
            frame('done', { conversation_id: 'c9' }, 3),
        )

        expect(events).toEqual([
            ['delta', { text: 'Hel' }, '1'],
            ['delta', { text: 'lo' }, '2'],
            ['done', { conversation_id: 'c9' }, '3'],
        ])
    })

    it('reassembles a frame split across chunks', async () => {
        const events = await collect('event: del', 'ta\ndata: {"te', 'xt":"split"}\n', '\n')

        expect(events).toEqual([['delta', { text: 'split' }, undefined]])
    })

    it('reads several frames arriving in one chunk', async () => {
        const events = await collect(frame('reasoning', { text: 'hm' }) + frame('delta', { text: 'hi' }))

        expect(events.map((e) => e[0])).toEqual(['reasoning', 'delta'])
    })

    it('joins multiple data lines with a newline before parsing', async () => {
        const events = await collect('event: delta\ndata: {"text":\ndata: "two lines"}\n\n')

        expect(events).toEqual([['delta', { text: 'two lines' }, undefined]])
    })

    it('terminates normally when the body simply ends, with no [DONE] sentinel', async () => {
        const onDone = vi.fn()

        await readSseStream(streamOf(frame('done', {})), () => {}, { onDone })

        expect(onDone).toHaveBeenCalledTimes(1)
    })

    it('dispatches a last frame that never got its blank line', async () => {
        const events = await collect('event: done\ndata: {"conversation_id":"c9"}')

        expect(events).toEqual([['done', { conversation_id: 'c9' }, undefined]])
    })

    it('ignores keepalive comments and frames with no data line', async () => {
        const events = await collect(': keepalive\n\n', 'id: 7\n\n', frame('delta', { text: 'x' }))

        expect(events).toEqual([['delta', { text: 'x' }, undefined]])
    })

    it('tolerates CRLF line endings, including a CRLF split across chunks', async () => {
        const events = await collect('event: delta\r\ndata: {"text":"crlf"}\r', '\n\r\n')

        expect(events).toEqual([['delta', { text: 'crlf' }, undefined]])
    })

    it('defaults the event name to message when the frame omits one', async () => {
        const events = await collect('data: {"text":"anon"}\n\n')

        expect(events).toEqual([['message', { text: 'anon' }, undefined]])
    })

    it('hands over a non-JSON payload as a raw string rather than throwing', async () => {
        const events = await collect('event: error\ndata: <html>502 Bad Gateway</html>\n\n')

        expect(events).toEqual([['error', '<html>502 Bad Gateway</html>', undefined]])
    })

    it('keeps a value that itself contains a colon intact', async () => {
        const events = await collect('event: delta\ndata: {"text":"https://example.com"}\n\n')

        expect(events).toEqual([['delta', { text: 'https://example.com' }, undefined]])
    })

    it('decodes multi-byte characters split across chunk boundaries', async () => {
        const encoded = new TextEncoder().encode(frame('delta', { text: 'مرحبا' }))
        const split = 20

        const body = new ReadableStream<Uint8Array>({
            start(controller) {
                controller.enqueue(encoded.slice(0, split))
                controller.enqueue(encoded.slice(split))
                controller.close()
            },
        })

        const events: any[] = []

        await readSseStream(new Response(body), (event, data) => events.push([event, data]))

        expect(events).toEqual([['delta', { text: 'مرحبا' }]])
    })

    it('stops on an abort signal without running onDone', async () => {
        const controller = new AbortController()
        const onDone = vi.fn()
        const seen: string[] = []

        await readSseStream(
            streamOf(frame('delta', { text: 'one' }), frame('delta', { text: 'two' })),
            (event) => {
                seen.push(event)
                controller.abort()
            },
            { onDone, signal: controller.signal },
        )

        expect(seen.length).toBeGreaterThanOrEqual(1)
        expect(onDone).not.toHaveBeenCalled()
    })

    it('returns immediately when the signal is already aborted', async () => {
        const onDone = vi.fn()
        const onEvent = vi.fn()

        await readSseStream(streamOf(frame('delta', { text: 'x' })), onEvent, {
            onDone,
            signal: AbortSignal.abort(),
        })

        expect(onEvent).not.toHaveBeenCalled()
        expect(onDone).not.toHaveBeenCalled()
    })

    it('rejects a response with no body', async () => {
        await expect(readSseStream(new Response(null), () => {})).rejects.toThrow(/no body/)
    })
})

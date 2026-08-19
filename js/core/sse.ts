/**
 * A reader for the kit's SSE frames, with no framework attached.
 *
 * The three apps all stream from a POST (a chat turn carries a body), which
 * rules out `EventSource` — every one of them hand-rolled a `fetch` +
 * `ReadableStream` reader instead. This is that reader, once.
 *
 * The FRAMING is not ours: `eventsource-parser` owns it, through its
 * `EventSourceParserStream` transform. It is the spec's grammar, maintained
 * and fuzzed by people who do only that, and it covers what our own loop
 * covered plus what it did not (a leading BOM, bare-CR terminators, a
 * `data:` payload split across hundreds of tiny chunks without the O(n²)
 * re-concatenation, `retry:`, NUL-bearing ids). What stays ours is the shell
 * around it: the fetch/abort lifecycle, `onDone`, the JSON-parse-with-
 * fallback, and one deliberate departure from the spec — see
 * {@link terminateFinalFrame}.
 *
 * It reads what `Saad\AiKit\Streaming\SseStream` writes —
 * `id: SEQ\nevent: NAME\ndata: {json}\n\n`, plus `: keepalive` comment
 * frames.
 *
 * `data` is JSON-parsed; a payload that is not JSON is handed over as the
 * raw string rather than throwing, so a proxy error page cannot take the
 * reader down.
 */
import { EventSourceParserStream } from 'eventsource-parser/stream'

export type SseEventHandler = (
    /** The `event:` name; `message` when the frame omits one. */
    event: string,
    /** The parsed `data:` payload — see {@link AiKitSseEvent} for the shapes. */
    data: any,
    /** The `id:` line, when present — the buffer sequence number to resume from. */
    id?: string,
) => void

export type ReadSseStreamOptions = {
    /** Runs once the response body ends normally (not on abort). */
    onDone?: () => void
    /** Aborts the read; the reader is cancelled and the promise resolves. */
    signal?: AbortSignal
    /**
     * Cap on the characters the parser may buffer for one unterminated
     * frame. Past it the returned promise rejects rather than growing the
     * buffer without bound — a proxy streaming a megabyte of HTML with no
     * blank line is not a turn worth holding in memory.
     */
    maxBufferSize?: number
}

/** Generous for a JSON event payload, small enough to bound a runaway body. */
const defaultMaxBufferSize = 1024 * 1024

export async function readSseStream(
    response: Response,
    onEvent: SseEventHandler,
    opts: ReadSseStreamOptions = {},
): Promise<void> {
    const body = response.body

    if (!body) {
        throw new Error('readSseStream: the response has no body to read.')
    }

    const { signal, maxBufferSize = defaultMaxBufferSize } = opts

    if (signal?.aborted) {
        void body.cancel().catch(() => {})

        return
    }

    const reader = body
        .pipeThrough(new TextDecoderStream())
        .pipeThrough(terminateFinalFrame())
        .pipeThrough(new EventSourceParserStream({ maxBufferSize }))
        .getReader()

    const cancel = () => {
        void reader.cancel().catch(() => {})
    }

    signal?.addEventListener('abort', cancel, { once: true })

    try {
        while (true) {
            const { done, value } = await reader.read()

            if (done) {
                break
            }

            onEvent(value.event ?? 'message', parseData(value.data), value.id)
        }
    } finally {
        signal?.removeEventListener('abort', cancel)
    }

    if (signal?.aborted) {
        return
    }

    opts.onDone?.()
}

/**
 * Feed a closing blank line when the body ends.
 *
 * Per the SSE spec a frame that arrives without its trailing blank line is
 * incomplete and must be discarded — so a server that streams
 * `event: done\ndata: {…}` and hangs up loses its last event. All three
 * apps' servers do exactly that on some paths, and a dropped `done` frame
 * leaves the client spinning forever. One synthetic terminator at
 * end-of-body flushes it. A body that already ended on a blank line gains a
 * frame with no `data:` line, which the parser drops on the floor.
 */
function terminateFinalFrame(): TransformStream<string, string> {
    return new TransformStream({
        transform(chunk, controller) {
            controller.enqueue(chunk)
        },
        flush(controller) {
            controller.enqueue('\n\n')
        },
    })
}

function parseData(raw: string): any {
    try {
        return JSON.parse(raw)
    } catch {
        // Not JSON — a proxy's error body, or a plain-text payload. Hand it
        // over as-is rather than tearing the stream down.
        return raw
    }
}

/**
 * A framework-free reader for the kit's SSE frames.
 *
 * The three apps all stream from a POST (a chat turn carries a body), which
 * rules out `EventSource` — every one of them hand-rolled a `fetch` +
 * `ReadableStream` reader instead. This is that reader, once.
 *
 * It parses what `Saad\AiKit\Streaming\SseStream` writes —
 * `id: SEQ\nevent: NAME\ndata: {json}\n\n`, plus `: keepalive` comment
 * frames — and tolerates the rest of the SSE grammar it may meet through a
 * proxy: CRLF line endings, frames split across chunks, multiple `data:`
 * lines in one frame (joined with a newline, per spec), and a last frame
 * that arrives without its trailing blank line.
 *
 * `data` is JSON-parsed; a payload that is not JSON is handed over as the
 * raw string rather than throwing, so a proxy error page cannot take the
 * reader down.
 */

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
}

export async function readSseStream(
    response: Response,
    onEvent: SseEventHandler,
    opts: ReadSseStreamOptions = {},
): Promise<void> {
    if (!response.body) {
        throw new Error('readSseStream: the response has no body to read.')
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    const { signal } = opts

    const cancel = () => {
        void reader.cancel().catch(() => {})
    }

    if (signal) {
        if (signal.aborted) {
            cancel()

            return
        }

        signal.addEventListener('abort', cancel, { once: true })
    }

    let buffer = ''
    // A chunk that ends mid-CRLF: the '\r' already terminated the line, and
    // an '\n' opening the next chunk belongs to that same terminator.
    let danglingCr = false

    const normalize = (chunk: string): string => {
        if (danglingCr) {
            chunk = '\n' + (chunk.startsWith('\n') ? chunk.slice(1) : chunk)
            danglingCr = false
        }

        if (chunk.endsWith('\r')) {
            danglingCr = true
            chunk = chunk.slice(0, -1)
        }

        return chunk.replace(/\r\n?/g, '\n')
    }

    const dispatch = (frame: string): void => {
        const parsed = parseFrame(frame)

        if (parsed) {
            onEvent(parsed.event, parsed.data, parsed.id)
        }
    }

    try {
        while (true) {
            const { done, value } = await reader.read()

            if (done) {
                break
            }

            buffer += normalize(decoder.decode(value, { stream: true }))

            let boundary = buffer.indexOf('\n\n')

            while (boundary !== -1) {
                dispatch(buffer.slice(0, boundary))
                buffer = buffer.slice(boundary + 2)
                boundary = buffer.indexOf('\n\n')
            }
        }
    } finally {
        signal?.removeEventListener('abort', cancel)
    }

    if (signal?.aborted) {
        return
    }

    // A final frame the server never closed with a blank line.
    buffer += normalize(decoder.decode())

    if (buffer.trim() !== '') {
        dispatch(buffer)
    }

    opts.onDone?.()
}

type ParsedFrame = { id?: string; event: string; data: any }

/**
 * One frame's fields. Comment lines are ignored, and a frame carrying no
 * `data:` line dispatches nothing (per the SSE spec) — which is how
 * keepalives and bare `id:` frames stay invisible to the caller.
 */
function parseFrame(frame: string): ParsedFrame | null {
    let id: string | undefined
    let event: string | undefined
    const data: string[] = []

    for (const line of frame.split('\n')) {
        if (line === '' || line.startsWith(':')) {
            continue
        }

        const colon = line.indexOf(':')
        const field = colon === -1 ? line : line.slice(0, colon)
        // One optional space after the colon is part of the delimiter.
        let value = colon === -1 ? '' : line.slice(colon + 1)

        if (value.startsWith(' ')) {
            value = value.slice(1)
        }

        if (field === 'data') {
            data.push(value)
        } else if (field === 'event') {
            event = value
        } else if (field === 'id') {
            id = value
        }
    }

    if (data.length === 0) {
        return null
    }

    const raw = data.join('\n')

    let parsed: any = raw

    try {
        parsed = JSON.parse(raw)
    } catch {
        // Not JSON — a proxy's error body, or a plain-text payload. Hand it
        // over as-is rather than tearing the stream down.
    }

    return { id, event: event ?? 'message', data: parsed }
}

/**
 * The shared markdown renderer: markdown in, sanitized HTML string out,
 * with no framework attached.
 *
 * Ported from s-grade's pipeline, which is the reference implementation of
 * the three (uqucc hand-rolled a regex parser that mis-renders headings and
 * nested lists; that one is retired by this module). Three properties are
 * load-bearing and the tests pin all three:
 *
 * 1. RAW HTML NEVER RENDERS. `remark-rehype` without `allowDangerousHtml`
 *    silently DROPS html nodes, which loses content the model wrote. We
 *    convert them to text instead, so `<script>` arrives as the literal
 *    characters the model typed and nothing goes missing.
 * 2. RENDERING NEVER THROWS. A parse or sanitize failure degrades to an
 *    HTML-escaped pre-wrap paragraph of the source. A malformed table mid-
 *    stream must not blank the assistant's message.
 * 3. EVERY LINK LEAVES SAFELY. `target="_blank" rel="noopener noreferrer
 *    nofollow"` is applied in a DOMPurify hook, after sanitization, so it
 *    cannot be talked out of by the markdown.
 *
 * No syntax highlighting and no math in v1 — both would multiply the
 * dependency footprint every consuming app inherits. Extend through
 * `plugins` instead.
 */
import type { PluggableList, Processor } from 'unified'
import { unified } from 'unified'
import remarkParse from 'remark-parse'
import remarkGfm from 'remark-gfm'
import remarkRehype from 'remark-rehype'
import rehypeStringify from 'rehype-stringify'
import DOMPurify from 'dompurify'

/**
 * Extra unified plugins. The phase matters — a remark plugin walks mdast
 * and a rehype plugin walks hast — so they are named rather than dumped
 * into one list: `remark` runs after GFM and before the mdast→hast
 * conversion, `rehype` after it and before stringification.
 *
 * Pass a stable object (module scope, not a fresh literal per render): the
 * built processor is cached against its identity.
 */
export type MarkdownPlugins = {
    remark?: PluggableList
    rehype?: PluggableList
}

export type MarkdownOptions = {
    plugins?: MarkdownPlugins
}

/** Render markdown to sanitized HTML. Never throws. */
export function renderMarkdown(src: string, options: MarkdownOptions = {}): string {
    if (src === '') {
        return ''
    }

    try {
        const html = String(processor(options.plugins).processSync(src))

        return sanitize(html)
    } catch {
        return preWrap(src)
    }
}

/** HTML-escape text for interpolation into markup. */
export function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
}

/**
 * The degraded rendering: the source as literal, wrapped text. Used as the
 * failure fallback and as the live path past {@link liveCharLimit}.
 */
export function preWrap(src: string): string {
    return `<p style="white-space:pre-wrap">${escapeHtml(src)}</p>`
}

export type LiveRendererOptions = MarkdownOptions & {
    /** Receives each re-render. */
    onHtml: (html: string) => void
    /** Trailing-edge throttle window while the turn streams. */
    throttleMs?: number
    /** Past this many characters, live re-renders degrade to pre-wrap. */
    liveCharLimit?: number
}

export type LiveRenderer = {
    /** Feed the accumulated text so far (not the delta). */
    push: (text: string) => void
    /** End the turn: cancel pending work and emit one full render. */
    finish: () => void
    /** Drop pending work and stop emitting (unmount, abort). */
    dispose: () => void
}

/**
 * Throttled rendering for a streaming turn.
 *
 * Re-rendering markdown on every delta is what makes a long answer crawl,
 * so re-renders coalesce onto a trailing edge: the first render lands
 * `throttleMs` after the first push, and at most one lands per window
 * after that. A very long answer stops being re-parsed entirely while live
 * and shows as pre-wrap — `finish()` always does one real render, so the
 * settled message is correct whatever the live path did.
 */
export function createLiveRenderer(options: LiveRendererOptions): LiveRenderer {
    const { onHtml, throttleMs = 250, liveCharLimit = 20000, plugins } = options

    let latest = ''
    let emitted: string | null = null
    let timer: ReturnType<typeof setTimeout> | null = null
    let disposed = false

    const emit = (text: string, live: boolean): void => {
        if (disposed || text === emitted) {
            return
        }

        emitted = text

        onHtml(live && text.length > liveCharLimit ? preWrap(text) : renderMarkdown(text, { plugins }))
    }

    return {
        push(text: string): void {
            if (disposed) {
                return
            }

            latest = text

            timer ??= setTimeout(() => {
                timer = null

                emit(latest, true)
            }, throttleMs)
        },

        finish(): void {
            if (timer !== null) {
                clearTimeout(timer)
                timer = null
            }

            if (disposed) {
                return
            }

            // The live path may have emitted this exact text as pre-wrap;
            // the final render has to land regardless.
            emitted = null

            emit(latest, false)
        },

        dispose(): void {
            if (timer !== null) {
                clearTimeout(timer)
                timer = null
            }

            disposed = true
        },
    }
}

type Md = Processor<any, any, any, any, string>

let defaultProcessor: Md | null = null
const extended = new WeakMap<object, Md>()

function processor(plugins?: MarkdownPlugins): Md {
    if (!plugins) {
        return (defaultProcessor ??= build())
    }

    let built = extended.get(plugins)

    if (!built) {
        built = build(plugins)
        extended.set(plugins, built)
    }

    return built
}

function build(plugins: MarkdownPlugins = {}): Md {
    return unified()
        .use(remarkParse)
        .use(remarkGfm)
        .use(plugins.remark ?? [])
        .use(remarkRehype, {
            // Raw html the model wrote becomes literal text. Without this
            // handler remark-rehype drops the node and the characters
            // vanish from the message.
            handlers: {
                html: (_state: unknown, node: { value: string }) => ({ type: 'text', value: node.value }),
            },
        })
        .use(plugins.rehype ?? [])
        .use(rehypeRawToText)
        .use(rehypeStringify) as unknown as Md
}

/**
 * Demote any `raw` hast nodes to text. The built-in path never produces
 * them; a caller-supplied plugin enabling `allowDangerousHtml` would, and
 * property 1 holds for those too.
 */
function rehypeRawToText() {
    const walk = (node: any): void => {
        if (!node || !Array.isArray(node.children)) {
            return
        }

        for (const child of node.children) {
            if (child?.type === 'raw') {
                child.type = 'text'
            }

            walk(child)
        }
    }

    return (tree: unknown) => walk(tree)
}

type Purifier = { sanitize: (html: string) => string }

let purifier: Purifier | null | undefined

/**
 * Sanitize with an instance of our own, so the link hook never leaks into
 * an app's other DOMPurify usage. Without a DOM (SSR, a worker) there is
 * nothing to sanitize with and the caller falls back to pre-wrap — we
 * never hand back unsanitized HTML.
 */
function sanitize(html: string): string {
    purifier ??= makePurifier()

    if (!purifier) {
        throw new Error('renderMarkdown: no DOM available to sanitize with.')
    }

    return purifier.sanitize(html)
}

function makePurifier(): Purifier | null {
    const view = typeof window === 'undefined' ? null : window

    if (!view) {
        return null
    }

    const instance = (DOMPurify as unknown as (w: unknown) => any)(view)

    if (!instance?.sanitize) {
        return null
    }

    instance.setConfig({ USE_PROFILES: { html: true } })

    instance.addHook('afterSanitizeAttributes', (node: any) => {
        if (node.tagName === 'A') {
            node.setAttribute('target', '_blank')
            node.setAttribute('rel', 'noopener noreferrer nofollow')
        }
    })

    return instance as Purifier
}

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
 *    nofollow"` is applied by a rehype plugin that runs AFTER the sanitizer,
 *    so it cannot be talked out of by the markdown.
 *
 * Sanitization is `rehype-sanitize` on the hast tree, not DOMPurify on the
 * output string. Same guarantee, three consequences: the pipeline no longer
 * needs a DOM, so it renders under SSR and in a worker instead of degrading
 * to pre-wrap there; nothing is serialized-then-reparsed, which is where a
 * mutation-XSS lives; and the allow list is a value in this file that the
 * tests can read, rather than a profile name plus a hook.
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
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize'
import rehypeStringify from 'rehype-stringify'
import remend from 'remend'

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
        return String(processor(options.plugins).processSync(src))
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
 * The whole pipeline re-runs over the WHOLE accumulated text on every
 * change, so rendering per delta is quadratic main-thread work that locks
 * the tab on a long reply. Renders are spaced at least `throttleMs` apart
 * and coalesce onto the trailing edge — but the FIRST push after an idle
 * window paints immediately, so a reply never looks stalled at the start
 * or after a pause for a tool call.
 *
 * Past `liveCharLimit` a still-streaming answer stops being parsed at all
 * and shows as pre-wrap: even throttled, re-parsing hundreds of KB per
 * tick locks the tab. `finish()` always does one real render, so a
 * legitimately long reply still settles fully formatted.
 */
export function createLiveRenderer(options: LiveRendererOptions): LiveRenderer {
    const { onHtml, throttleMs = 250, liveCharLimit = 20000, plugins } = options

    let latest = ''
    let emitted: string | null = null
    let timer: ReturnType<typeof setTimeout> | null = null
    let renderedAt = 0
    let disposed = false

    const emit = (text: string, live: boolean): void => {
        if (disposed || text === emitted) {
            return
        }

        emitted = text
        renderedAt = Date.now()

        if (live && text.length > liveCharLimit) {
            onHtml(preWrap(text))

            return
        }

        onHtml(renderMarkdown(live ? mend(text) : text, { plugins }))
    }

    return {
        push(text: string): void {
            if (disposed) {
                return
            }

            latest = text

            if (timer !== null) {
                return
            }

            timer = setTimeout(
                () => {
                    timer = null

                    emit(latest, true)
                },
                Math.max(0, renderedAt + throttleMs - Date.now()),
            )
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

/**
 * Close the markdown the model has not finished writing yet.
 *
 * A partial buffer ends mid-syntax constantly: `**bold` renders its two
 * asterisks as literal characters for one frame, then they vanish when the
 * closing pair arrives, and the same flicker hits `` ` ``, `~~`, `[text](`
 * and `$$`. `remend` completes the open construct for the render only — the
 * buffer we were handed is never modified, and `finish()` renders the real
 * text, so nothing invented here survives into the settled message.
 *
 * `text-only` for links because a completed `[text](…` would otherwise carry
 * remend's `streamdown:` placeholder href, which our sanitizer strips a
 * moment later and leaves a dead anchor behind. Math stays off: the pipeline
 * ships no KaTeX, so closing `$$` would only add characters that render as
 * themselves.
 */
function mend(text: string): string {
    return remend(text, { linkMode: 'text-only', katex: false, inlineKatex: false })
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
        // Before the sanitizer: it drops `raw` nodes outright, and property 1
        // says the characters survive as text.
        .use(rehypeRawToText)
        .use(rehypeSanitize, sanitizeSchema)
        // After the sanitizer, deliberately: an unsafe `href` is already gone
        // by now, so the guard below cannot be fooled into decorating one,
        // and the values we write are fixed literals no markdown can reach.
        .use(rehypeSafeLinks)
        // Named references so an escaped `<` serializes as `&lt;` rather than
        // `&#x3C;`. Identical to a browser either way; it keeps the output
        // byte-comparable with what the previous DOMPurify pass produced, and
        // legible in a failing assertion.
        .use(rehypeStringify, { characterReferences: { useNamedReferences: true } }) as unknown as Md
}

/**
 * The allow list, derived from `hast-util-sanitize`'s GitHub-shaped default.
 *
 * The default is the right base — it already excludes `javascript:`,
 * `data:` and `vbscript:` from every URL field, prefixes id-like attributes
 * to stop DOM clobbering, and permits only the element set a markdown
 * document can produce. Two departures:
 */
const sanitizeSchema = {
    ...defaultSchema,
    attributes: {
        ...defaultSchema.attributes,
        // The default denies every class on `code`, which takes the fence's
        // `language-js` with it — the hook highlighters and `prose.css` both
        // key on. Allow that one shape and nothing else.
        code: [['className', /^language-[\w+#.-]+$/]],
    },
    protocols: {
        ...defaultSchema.protocols,
        // The default carries `irc`, `ircs` and `xmpp` from the SSE-era spec
        // list. Nothing these apps answer with links to them, and a scheme
        // we do not need is a scheme we do not have to reason about.
        href: ['http', 'https', 'mailto', 'tel'],
    },
} satisfies typeof defaultSchema

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

/**
 * Send every real link to a new tab with the referrer and ranking dropped.
 *
 * Only real links: a heading-anchor plugin emits `<a>` elements with no
 * href, and those are not navigation. Neither is an `<a>` whose href the
 * sanitizer just removed for being `javascript:` — which is why this runs
 * after it rather than before.
 */
function rehypeSafeLinks() {
    const walk = (node: any): void => {
        if (node?.type === 'element' && node.tagName === 'a' && node.properties?.href) {
            node.properties.target = '_blank'
            node.properties.rel = ['noopener', 'noreferrer', 'nofollow']
        }

        if (Array.isArray(node?.children)) {
            for (const child of node.children) {
                walk(child)
            }
        }
    }

    return (tree: unknown) => walk(tree)
}

<script lang="ts">
    /**
     * Renders assistant markdown, sanitized, with a throttled live path for
     * a turn that is still streaming. Mirror of the Vue component; see
     * `js/vue/Markdown.vue` for the same notes.
     *
     * Feed `source` the accumulated text (not the delta) and flip `live` to
     * false when the turn's terminal event lands — that triggers the one
     * full, unthrottled render of the settled message.
     */
    import { createLiveRenderer, renderMarkdown, type LiveRenderer, type MarkdownPlugins } from '../core/markdown'

    let {
        source,
        live = false,
        dir = 'auto',
        throttleMs = 250,
        plugins = undefined,
        onLinkClick = undefined,
    }: {
        source: string
        live?: boolean
        dir?: string
        throttleMs?: number
        plugins?: MarkdownPlugins
        /**
         * Intercept anchor clicks — an assistant reply linking to an in-app
         * route should navigate through the router, not reload the page.
         * Without it links follow their hardened `target`/`rel`.
         */
        onLinkClick?: (href: string) => void
    } = $props()

    let html = $state('')

    let renderer: LiveRenderer | null = null

    $effect(() => {
        const text = source

        if (live) {
            renderer ??= createLiveRenderer({
                throttleMs,
                plugins,
                onHtml: (rendered) => {
                    html = rendered
                },
            })

            renderer.push(text)

            return
        }

        if (renderer) {
            // The turn just ended: settle whatever the throttle was holding.
            renderer.push(text)
            renderer.finish()
            renderer.dispose()
            renderer = null

            return
        }

        html = renderMarkdown(text, { plugins })
    })

    $effect(() => () => {
        renderer?.dispose()
        renderer = null
    })

    // Delegated, so it keeps working as the live render replaces the markup.
    function handleClick(event: MouseEvent): void {
        if (onLinkClick === undefined) {
            return
        }

        const anchor = (event.target as HTMLElement | null)?.closest('a[href]')

        if (anchor) {
            event.preventDefault()
            onLinkClick(anchor.getAttribute('href') ?? '')
        }
    }
</script>

<!-- eslint-disable-next-line svelte/no-at-html-tags — the renderer sanitizes. -->
<!-- svelte-ignore a11y_no_static_element_interactions, a11y_click_events_have_key_events — delegated anchor handling only -->
<div class="ai-kit-markdown" {dir} onclick={handleClick}>{@html html}</div>

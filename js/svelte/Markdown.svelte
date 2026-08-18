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
    }: {
        source: string
        live?: boolean
        dir?: string
        throttleMs?: number
        plugins?: MarkdownPlugins
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
</script>

<!-- The renderer sanitizes; see js/core/markdown.ts. -->
<div class="ai-kit-markdown" {dir}>{@html html}</div>

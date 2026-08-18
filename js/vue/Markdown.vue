<script setup lang="ts">
/**
 * Renders assistant markdown, sanitized, with a throttled live path for a
 * turn that is still streaming.
 *
 * Feed `source` the accumulated text (not the delta) and flip `live` to
 * false when the turn's terminal event lands — that triggers the one full,
 * unthrottled render of the settled message.
 *
 * Deliberately unstyled beyond direction: the markup lands under
 * `.ai-kit-markdown` and the app owns its typography.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import { createLiveRenderer, renderMarkdown, type LiveRenderer, type MarkdownPlugins } from '../core/markdown'

const props = withDefaults(
    defineProps<{
        /** Markdown source — the accumulated text so far while streaming. */
        source: string
        /** Whether the turn is still streaming. */
        live?: boolean
        /** Passed straight to the wrapper; `auto` handles mixed RTL/LTR replies. */
        dir?: string
        /** Live re-render window in milliseconds. */
        throttleMs?: number
        /** Extra unified plugins; pass a stable object, not a fresh literal. */
        plugins?: MarkdownPlugins
        /**
         * Intercept anchor clicks — an assistant reply linking to an in-app
         * route should navigate through the router, not reload the page.
         * Without it links follow their hardened `target`/`rel`.
         */
        onLinkClick?: (href: string) => void
    }>(),
    {
        live: false,
        dir: 'auto',
        throttleMs: 250,
    },
)

const html = ref('')

let renderer: LiveRenderer | null = null

const dispose = (): void => {
    renderer?.dispose()
    renderer = null
}

watch(
    [() => props.source, () => props.live],
    ([source, live]) => {
        if (live) {
            renderer ??= createLiveRenderer({
                throttleMs: props.throttleMs,
                plugins: props.plugins,
                onHtml: (rendered) => {
                    html.value = rendered
                },
            })

            renderer.push(source)

            return
        }

        if (renderer) {
            // The turn just ended: settle whatever the throttle was holding.
            renderer.push(source)
            renderer.finish()
            dispose()

            return
        }

        html.value = renderMarkdown(source, { plugins: props.plugins })
    },
    { immediate: true },
)

onBeforeUnmount(dispose)

// Delegated, so it keeps working as the live render replaces the markup.
const onClick = (event: MouseEvent): void => {
    if (!props.onLinkClick) {
        return
    }

    const anchor = (event.target as HTMLElement | null)?.closest('a[href]')

    if (anchor) {
        event.preventDefault()
        props.onLinkClick(anchor.getAttribute('href') ?? '')
    }
}
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- the renderer sanitizes. -->
    <div class="ai-kit-markdown" :dir="dir" v-html="html" @click="onClick" />
</template>

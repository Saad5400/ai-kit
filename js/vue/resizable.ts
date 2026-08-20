/**
 * The Vue side of `js/core/resizable.ts`: a composable handing back the two
 * template refs to bind. Mirror of `js/svelte/resizable.ts`.
 *
 *     <script setup lang="ts">
 *     import { useResizable } from '@saad5400/ai-kit/vue/resizable'
 *     import '@saad5400/ai-kit/styles/resizable.css'
 *
 *     const { handle, panel, width } = useResizable({ storageKey: 'assistant.width' })
 *     </script>
 *
 *     <template>
 *         <aside ref="panel" class="assistant">
 *             <div ref="handle" class="ai-kit-resize-handle" />
 *             …
 *         </aside>
 *     </template>
 *
 * The helper is created once both elements exist and re-created if either is
 * swapped (a `v-if` around the panel), then destroyed with the component.
 * `width` mirrors the width in force — `null` below the desktop breakpoint,
 * which is what a layout that stacks on mobile wants to read.
 */

import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { createResizable, type Resizable, type ResizableOptions } from '../core/resizable'

/** Every core option except the two elements, which are the returned refs. */
export type UseResizableOptions = Omit<ResizableOptions, 'handle' | 'panel'>

export type UseResizable = {
    /** Bind to the drag handle: `<div ref="handle" />`. */
    handle: Ref<HTMLElement | null>
    /** Bind to the panel being resized: `<aside ref="panel">`. */
    panel: Ref<HTMLElement | null>
    /** The width in force, or `null` when none is applied. */
    width: Ref<number | null>
    /** Set the width programmatically; clamped and persisted like a drag. */
    resize(width: number): void
    /** Forget the stored width and hand the panel back to the stylesheet. */
    reset(): void
}

export function useResizable(options: UseResizableOptions): UseResizable {
    const handle = ref<HTMLElement | null>(null)
    const panel = ref<HTMLElement | null>(null)
    const width = ref<number | null>(null)

    let instance: Resizable | null = null

    const stop = (): void => {
        instance?.destroy()
        instance = null
    }

    watch(
        [handle, panel],
        ([handleEl, panelEl]) => {
            stop()

            if (handleEl === null || panelEl === null) {
                return
            }

            instance = createResizable({
                ...options,
                handle: handleEl,
                panel: panelEl,
                onresize: (next) => {
                    width.value = next
                    options.onresize?.(next)
                },
            })
        },
        { flush: 'post' },
    )

    onBeforeUnmount(stop)

    return {
        handle,
        panel,
        width,
        resize: (next: number) => instance?.resize(next),
        reset: () => instance?.reset(),
    }
}

/**
 * The Svelte side of `js/core/resizable.ts`: a use:action on the HANDLE, given
 * the panel it resizes. Mirror of `js/vue/resizable.ts`.
 *
 *     <script lang="ts">
 *         import { resizable } from '@saad5400/ai-kit/svelte/resizable'
 *         import '@saad5400/ai-kit/styles/resizable.css'
 *
 *         let panel = $state<HTMLElement | null>(null)
 *     </script>
 *
 *     <aside class="assistant" bind:this={panel}>
 *         {#if panel}
 *             <div class="ai-kit-resize-handle" use:resizable={{ panel, storageKey: 'assistant.width' }}></div>
 *         {/if}
 *         …
 *     </aside>
 *
 * The action re-creates the helper when its options change, so a reactive
 * `min`/`max`/`storageKey` is safe; the panel element itself normally never
 * changes. Everything else — RTL delta, the desktop breakpoint, persistence —
 * is the core helper's, unchanged.
 */

import { createResizable, type Resizable, type ResizableOptions } from '../core/resizable'

/** Every core option except `handle`, which is the node the action is on. */
export type ResizableActionOptions = Omit<ResizableOptions, 'handle'>

export function resizable(
    node: HTMLElement,
    options: ResizableActionOptions,
): { update(next: ResizableActionOptions): void; destroy(): void } {
    let instance: Resizable = createResizable({ ...options, handle: node })

    return {
        update(next: ResizableActionOptions): void {
            instance.destroy()
            instance = createResizable({ ...next, handle: node })
        },
        destroy(): void {
            instance.destroy()
        },
    }
}

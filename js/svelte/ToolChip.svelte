<script lang="ts">
    /**
     * One tool's status, from the `tool` wire event. Mirror of
     * `js/vue/ToolChip.vue`.
     *
     * The parent keys chips by the event's `id` and updates the same chip
     * when the `done` event for that id arrives. `name` may be the raw tool
     * identifier or a localized label an app swapped in before the timeline
     * (uqucc and catodemy both do), so it renders `dir="auto"` — a hardcoded
     * `ltr` would scramble an Arabic label's bidi (a trailing ellipsis lands
     * at the visual right, glued to the status mark).
     *
     * A running chip gets its trailing ellipsis from CSS, never from the
     * label text: `::after` sits at the inline end so it follows the label's
     * own direction, and it disappears the moment the chip settles — a baked
     * "…" would keep reading "still working" under a ✓.
     */
    import type { ToolPayload } from '../core/events'

    let {
        name,
        status,
        successful = true,
    }: {
        name: string
        status: ToolPayload['status']
        successful?: boolean
    } = $props()

    let failed = $derived(status === 'done' && successful === false)
</script>

<span class="ai-kit-tool-chip" class:is-running={status === 'running'} class:is-failed={failed} title={name}>
    {#if status === 'running'}
        <span class="ai-kit-tool-chip__spinner" aria-hidden="true"></span>
    {:else}
        <span class="ai-kit-tool-chip__mark" aria-hidden="true">{failed ? '✗' : '✓'}</span>
    {/if}
    <span class="ai-kit-tool-chip__name" dir="auto">{name}</span>
</span>

<style>
    .ai-kit-tool-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.125rem 0.5rem;
        border-radius: 999px;
        font-size: var(--ai-kit-chip-size, 0.75rem);
        line-height: 1.5;
        /* Derived from the host's own text color, so the chip reads on a dark
           panel as well as a light one without any app CSS. */
        color: var(--ai-kit-chip-color, inherit);
        background: var(--ai-kit-chip-bg, color-mix(in oklab, currentColor 12%, transparent));
        border: 1px solid var(--ai-kit-chip-border, transparent);
        unicode-bidi: isolate;
    }

    .ai-kit-tool-chip.is-failed {
        color: var(--ai-kit-chip-failed-color, var(--ai-kit-destructive, #ef4444));
        background: var(--ai-kit-chip-failed-bg, color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 15%, transparent));
    }

    .ai-kit-tool-chip__name {
        font-family: var(--ai-kit-chip-font, ui-monospace, SFMono-Regular, Menlo, monospace);
        unicode-bidi: isolate;
    }

    .ai-kit-tool-chip.is-running .ai-kit-tool-chip__name::after {
        content: '…';
    }

    .ai-kit-tool-chip__spinner {
        width: 0.625rem;
        height: 0.625rem;
        border-radius: 50%;
        border: 1.5px solid currentColor;
        border-top-color: transparent;
        animation: ai-kit-chip-spin 0.8s linear infinite;
    }

    @keyframes ai-kit-chip-spin {
        to {
            transform: rotate(360deg);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .ai-kit-tool-chip__spinner {
            animation-duration: 2.4s;
        }
    }
</style>

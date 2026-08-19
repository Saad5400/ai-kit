<script lang="ts">
    /**
     * The collapsible thinking block for the `reasoning` channel. Mirror of
     * `js/vue/ThinkingDisclosure.vue`.
     *
     * @deprecated since 0.6.0 — use `ProcessGroup.svelte` over
     * `groupSegments(timeline.segments)`. A single accumulated `text` prop
     * cannot express a turn that thought more than once, which is what pinned
     * the thinking block to the top of every message. Still exported for one
     * version.
     *
     * The wire carries no start/end brackets (see `ReasoningPayload`): the
     * parent accumulates `reasoning` deltas into `text` and sets `live`
     * false once a `delta`, `tool` or terminal event closes the block.
     *
     * It opens itself when reasoning starts and then leaves the state
     * alone — collapsing a finished block is the PARENT's call, because
     * whether a settled answer should hide its thinking differs per app. A
     * parent that wants auto-collapse can remount with `{#key live}`.
     *
     * The body is NOT markdown: reasoning is a raw model channel, often
     * half-formed, and parsing it mid-stream produces flicker.
     */
    import { untrack } from 'svelte'

    let {
        text,
        live = false,
        label = 'تفكير',
    }: {
        text: string
        live?: boolean
        label?: string
    } = $props()

    // Only the initial value: after mount the effect below opens it, and
    // the user's own toggling wins from there.
    let open = $state(untrack(() => live))

    $effect(() => {
        if (live) {
            open = true
        }
    })
</script>

<details class="ai-kit-thinking" bind:open>
    <summary class="ai-kit-thinking__label">
        <span>{label}</span>
        {#if live}
            <span class="ai-kit-thinking__pulse" aria-hidden="true"></span>
        {/if}
    </summary>
    <div class="ai-kit-thinking__body" dir="auto">{text}</div>
</details>

<style>
    .ai-kit-thinking {
        color: var(--ai-kit-muted, #6b7280);
        font-size: var(--ai-kit-thinking-size, 0.875rem);
        border-inline-start: 2px solid var(--ai-kit-thinking-border, currentColor);
        padding-inline-start: 0.75rem;
    }

    .ai-kit-thinking__label {
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.375rem;
        list-style: none;
        user-select: none;
    }

    .ai-kit-thinking__pulse {
        width: 0.375rem;
        height: 0.375rem;
        border-radius: 50%;
        background: currentColor;
        animation: ai-kit-thinking-pulse 1.2s ease-in-out infinite;
    }

    .ai-kit-thinking__body {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        margin-block-start: 0.375rem;
        opacity: var(--ai-kit-thinking-opacity, 0.85);
    }

    @keyframes ai-kit-thinking-pulse {
        0%,
        100% {
            opacity: 0.25;
        }
        50% {
            opacity: 1;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .ai-kit-thinking__pulse {
            animation: none;
        }
    }
</style>

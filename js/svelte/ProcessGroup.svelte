<script lang="ts">
    /**
     * One `process` group from `groupSegments()`: a turn's thinking AND its
     * tool calls behind a SINGLE disclosure, in item order. Mirror of
     * `js/vue/ProcessGroup.vue`.
     *
     * This replaces `ThinkingDisclosure` for the timeline model — a
     * per-thought disclosure fragments a turn that thought three times, and a
     * chip row detached from the thinking loses which thought preceded which
     * call.
     *
     * The parent decides `live`: pass `true` only while the turn is streaming
     * AND this is the last group, which keeps the tail open and lets earlier
     * groups collapse on their own. Once open, the user's toggling wins.
     *
     * Thinking text is NOT markdown: it is a raw model channel, often
     * half-formed, and parsing it mid-stream produces flicker.
     */
    import { untrack } from 'svelte'
    import type { ProcessGroup } from '../core/timeline'
    import ToolChip from './ToolChip.svelte'

    let {
        items,
        live = false,
        label = 'خطوات التفكير',
    }: {
        items: ProcessGroup['items']
        live?: boolean
        label?: string
    } = $props()

    // Only the initial value: the effect below opens it while live, and the
    // user's own toggling wins from there.
    let open = $state(untrack(() => live))

    $effect(() => {
        if (live) {
            open = true
        }
    })
</script>

<details class="ai-kit-process" bind:open>
    <summary class="ai-kit-process__label">
        <span>{label}</span>
        {#if live}
            <span class="ai-kit-process__pulse" aria-hidden="true"></span>
        {/if}
    </summary>
    <div class="ai-kit-process__body">
        {#each items as item, index (index)}
            {#if item.type === 'thinking'}
                <div class="ai-kit-process__thinking" dir="auto">{item.text}</div>
            {:else}
                <ToolChip name={item.name} status={item.status} successful={item.successful} />
            {/if}
        {/each}
    </div>
</details>

<style>
    .ai-kit-process {
        color: var(--ai-kit-muted, #6b7280);
        font-size: var(--ai-kit-thinking-size, 0.875rem);
        border-inline-start: 2px solid var(--ai-kit-thinking-border, currentColor);
        padding-inline-start: 0.75rem;
    }

    .ai-kit-process__label {
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.375rem;
        list-style: none;
        user-select: none;
    }

    .ai-kit-process__pulse {
        width: 0.375rem;
        height: 0.375rem;
        border-radius: 50%;
        background: currentColor;
        animation: ai-kit-process-pulse 1.2s ease-in-out infinite;
    }

    .ai-kit-process__body {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.375rem;
        margin-block-start: 0.375rem;
    }

    .ai-kit-process__thinking {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        opacity: var(--ai-kit-thinking-opacity, 0.85);
    }

    @keyframes ai-kit-process-pulse {
        0%,
        100% {
            opacity: 0.25;
        }
        50% {
            opacity: 1;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .ai-kit-process__pulse {
            animation: none;
        }
    }
</style>

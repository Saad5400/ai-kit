<script setup lang="ts">
/**
 * One `process` group from `groupSegments()`: a turn's thinking AND its tool
 * calls behind a SINGLE disclosure, rendered in item order.
 *
 * This replaces `ThinkingDisclosure` for the timeline model — a per-thought
 * disclosure fragments a turn that thought three times, and a chip row detached
 * from the thinking loses which thought preceded which call. Answer text never
 * appears in here: `groupSegments()` keeps `text` segments top-level, so a
 * settled answer is never hidden behind a summary.
 *
 * OPEN STATE. The parent decides `live`: pass `true` only while the turn is
 * streaming AND this is the last group. The disclosure then opens while live and
 * collapses on its own once the group settles — but the moment the user toggles
 * it by hand, their choice sticks and this stops managing it.
 *
 * Thinking text is NOT markdown: it is a raw model channel, often half-formed,
 * and parsing it mid-stream produces flicker.
 */
import { computed, ref, watch } from 'vue'
import type { ProcessGroup } from '../core/timeline'
import ToolChip from './ToolChip.vue'

const props = withDefaults(
    defineProps<{
        items: ProcessGroup['items']
        /** Whether the model is still working inside this group. */
        live?: boolean
        label?: string
    }>(),
    {
        live: false,
        label: 'خطوات التفكير',
    },
)

const tools = computed(() => props.items.filter((item) => item.type === 'tool').length)

const open = ref(props.live)
const touched = ref(false)

watch(
    () => props.live,
    (live) => {
        if (!touched.value) {
            open.value = live
        }
    },
)

// Fires for our own changes too, so a real user toggle is the one that arrives
// disagreeing with the state we set.
const onToggle = (event: Event): void => {
    const next = (event.target as HTMLDetailsElement).open

    if (next !== open.value) {
        touched.value = true
        open.value = next
    }
}
</script>

<template>
    <details class="ai-kit-process" :open="open" @toggle="onToggle">
        <summary class="ai-kit-process__summary">
            <svg class="ai-kit-process__chevron" viewBox="0 0 12 12" aria-hidden="true">
                <path d="M3 4.5 6 7.5 9 4.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span class="ai-kit-process__label" dir="auto">{{ label }}</span>
            <span v-if="tools > 0" class="ai-kit-process__badge" dir="ltr">{{ tools }}</span>
            <span v-if="live" class="ai-kit-process__pulse" aria-hidden="true" />
        </summary>
        <div class="ai-kit-process__body">
            <template v-for="(item, index) in items" :key="index">
                <div v-if="item.type === 'thinking'" class="ai-kit-process__thinking" dir="auto">{{ item.text }}</div>
                <ToolChip v-else :name="item.name" :status="item.status" :successful="item.successful" />
            </template>
        </div>
    </details>
</template>

<style scoped>
.ai-kit-process {
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
    font-size: var(--ai-kit-thinking-size, 0.8125rem);
}

.ai-kit-process__summary {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.1875rem 0.5rem;
    border-radius: var(--ai-kit-radius, 0.5rem);
    background: var(--ai-kit-surface, color-mix(in oklab, currentColor 8%, transparent));
    cursor: pointer;
    list-style: none;
    user-select: none;
}

/* Safari still paints its own triangle without this. */
.ai-kit-process__summary::-webkit-details-marker {
    display: none;
}

.ai-kit-process__summary:focus-visible {
    outline: 2px solid var(--ai-kit-accent, #3b82f6);
    outline-offset: 1px;
}

/* Down when open, up when closed — direction-neutral, so RTL needs no mirror. */
.ai-kit-process__chevron {
    width: 0.75em;
    height: 0.75em;
    flex: none;
    transform: rotate(180deg);
    transition: transform 0.15s ease;
}

.ai-kit-process[open] .ai-kit-process__chevron {
    transform: rotate(0deg);
}

.ai-kit-process__label {
    font-weight: 500;
}

.ai-kit-process__badge {
    padding-inline: 0.3125rem;
    border-radius: 999px;
    background: var(--ai-kit-surface, color-mix(in oklab, currentColor 12%, transparent));
    font-size: 0.6875rem;
    font-variant-numeric: tabular-nums;
    unicode-bidi: isolate;
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
    padding-inline-start: 0.75rem;
    border-inline-start: 2px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
}

.ai-kit-process__thinking {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.7;
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

    .ai-kit-process__chevron {
        transition: none;
    }
}
</style>

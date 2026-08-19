<script setup lang="ts">
/**
 * One `process` group from `groupSegments()`: a turn's thinking AND its tool
 * calls behind a SINGLE disclosure, rendered in item order.
 *
 * This replaces `ThinkingDisclosure` for the timeline model — a per-thought
 * disclosure fragments a turn that thought three times, and a chip row
 * detached from the thinking loses which thought preceded which call.
 *
 * The parent decides `live`: pass `true` only while the turn is streaming AND
 * this is the last group, which is what keeps the tail open and lets earlier
 * groups collapse on their own. Once open, the user's own toggling wins.
 *
 * Thinking text is NOT markdown: it is a raw model channel, often half-formed,
 * and parsing it mid-stream produces flicker.
 */
import { ref, watch } from 'vue'
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

const open = ref(props.live)

watch(
    () => props.live,
    (live) => {
        if (live) {
            open.value = true
        }
    },
)

const onToggle = (event: Event): void => {
    open.value = (event.target as HTMLDetailsElement).open
}
</script>

<template>
    <details class="ai-kit-process" :open="open" @toggle="onToggle">
        <summary class="ai-kit-process__label">
            <span>{{ label }}</span>
            <span v-if="live" class="ai-kit-process__pulse" aria-hidden="true" />
        </summary>
        <div class="ai-kit-process__body">
            <template v-for="(item, index) in items" :key="index">
                <div v-if="item.type === 'thinking'" class="ai-kit-process__thinking" dir="auto">{{ item.text }}</div>
                <ToolChip
                    v-else
                    :name="item.name"
                    :status="item.status"
                    :successful="item.successful"
                />
            </template>
        </div>
    </details>
</template>

<style scoped>
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

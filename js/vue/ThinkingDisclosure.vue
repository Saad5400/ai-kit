<script setup lang="ts">
/**
 * The collapsible thinking block for the `reasoning` channel.
 *
 * @deprecated since 0.6.0 — use `ProcessGroup.vue` over
 * `groupSegments(timeline.segments)`. A single accumulated `text` prop cannot
 * express a turn that thought more than once, which is what pinned the
 * thinking block to the top of every message. Still exported for one version.
 *
 * The wire carries no start/end brackets (see `ReasoningPayload`): the
 * parent accumulates `reasoning` deltas into `text` and sets `live` false
 * once a `delta`, `tool` or terminal event closes the block.
 *
 * It opens itself when reasoning starts and then leaves the state alone —
 * collapsing a finished block is the PARENT's call, not this component's,
 * because whether a settled answer should hide its thinking is a product
 * decision that differs per app. A parent that wants auto-collapse can
 * force a remount with `:key="live"`.
 *
 * The body is NOT markdown: reasoning is a raw model channel, often
 * half-formed, and parsing it mid-stream produces flicker. It renders as
 * pre-wrap text.
 */
import { ref, watch } from 'vue'

const props = withDefaults(
    defineProps<{
        /** The accumulated reasoning text. */
        text: string
        /** Whether reasoning is still arriving. */
        live?: boolean
        label?: string
    }>(),
    {
        live: false,
        label: 'تفكير',
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
    <details class="ai-kit-thinking" :open="open" @toggle="onToggle">
        <summary class="ai-kit-thinking__label">
            <span>{{ label }}</span>
            <span v-if="live" class="ai-kit-thinking__pulse" aria-hidden="true" />
        </summary>
        <div class="ai-kit-thinking__body" dir="auto">{{ text }}</div>
    </details>
</template>

<style scoped>
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

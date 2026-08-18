<script setup lang="ts">
/**
 * One tool's status, from the `tool` wire event.
 *
 * The parent keys chips by the event's `id` and updates the same chip when
 * the `done` event for that id arrives. Tool names are identifiers, so the
 * name renders `dir="ltr"` even inside an RTL bubble.
 */
import { computed } from 'vue'
import type { ToolPayload } from '../core/events'

const props = withDefaults(
    defineProps<{
        name: string
        status: ToolPayload['status']
        successful?: boolean
    }>(),
    {
        successful: true,
    },
)

const failed = computed(() => props.status === 'done' && props.successful === false)
</script>

<template>
    <span
        class="ai-kit-tool-chip"
        :class="{ 'is-running': status === 'running', 'is-failed': failed }"
        :title="name"
    >
        <span v-if="status === 'running'" class="ai-kit-tool-chip__spinner" aria-hidden="true" />
        <span v-else class="ai-kit-tool-chip__mark" aria-hidden="true">{{ failed ? '✗' : '✓' }}</span>
        <span class="ai-kit-tool-chip__name" dir="ltr">{{ name }}</span>
    </span>
</template>

<style scoped>
.ai-kit-tool-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    font-size: var(--ai-kit-chip-size, 0.75rem);
    line-height: 1.5;
    color: var(--ai-kit-chip-color, #4b5563);
    background: var(--ai-kit-chip-bg, rgba(107, 114, 128, 0.12));
    border: 1px solid var(--ai-kit-chip-border, transparent);
}

.ai-kit-tool-chip.is-failed {
    color: var(--ai-kit-chip-failed-color, #b91c1c);
    background: var(--ai-kit-chip-failed-bg, rgba(185, 28, 28, 0.12));
}

.ai-kit-tool-chip__name {
    font-family: var(--ai-kit-chip-font, ui-monospace, SFMono-Regular, Menlo, monospace);
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

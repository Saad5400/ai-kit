<script setup lang="ts">
/**
 * One tool's status, from the `tool` wire event.
 *
 * The parent keys chips by the event's `id` and updates the same chip when
 * the `done` event for that id arrives. `name` may be the raw tool identifier
 * or a localized label an app swapped in before the timeline (uqucc and
 * catodemy both do), so it renders `dir="auto"` — a hardcoded `ltr` would
 * scramble an Arabic label's bidi (a trailing ellipsis lands at the visual
 * right, glued to the status mark).
 *
 * A running chip gets its trailing ellipsis from CSS, never from the label
 * text: `::after` sits at the inline end so it follows the label's own
 * direction, and it disappears the moment the chip settles — a baked "…"
 * would keep reading "still working" under a ✓.
 *
 * PROGRESS. A long tool reports `progress` through the same `tool` event (see
 * `ToolProgress` in the wire contract). While running, the chip shows its
 * `label` after the name, `current/total` as `12/40` in an LTR-isolated span
 * (so the digits do not flip inside an Arabic host), and — when `percent` or
 * `current/total` is known — a thin determinate bar in place of the spinner.
 * With no figure to draw, the spinner stays. None of it survives `done`: the
 * timeline drops `progress` there.
 */
import { computed } from 'vue'
import type { ToolPayload, ToolProgress } from '../core/events'

const props = withDefaults(
    defineProps<{
        name: string
        status: ToolPayload['status']
        successful?: boolean
        progress?: ToolProgress
    }>(),
    {
        successful: true,
        progress: undefined,
    },
)

const running = computed(() => props.status === 'running')
const failed = computed(() => props.status === 'done' && props.successful === false)
const live = computed(() => (running.value ? props.progress : undefined))

/** The bar's fill, 0–100, or null when nothing determinate was reported. */
const percent = computed((): number | null => {
    const progress = live.value

    if (progress === undefined) {
        return null
    }

    if (typeof progress.percent === 'number' && Number.isFinite(progress.percent)) {
        return Math.min(100, Math.max(0, progress.percent))
    }

    if (
        typeof progress.current === 'number' &&
        typeof progress.total === 'number' &&
        progress.total > 0
    ) {
        return Math.min(100, Math.max(0, (progress.current / progress.total) * 100))
    }

    return null
})

/** `12/40` — or just `12` when the tool knows no total. */
const count = computed((): string | null => {
    const progress = live.value

    if (progress === undefined || typeof progress.current !== 'number') {
        return null
    }

    return typeof progress.total === 'number' ? `${progress.current}/${progress.total}` : `${progress.current}`
})
</script>

<template>
    <span
        class="ai-kit-tool-chip"
        :class="{ 'is-running': running, 'is-failed': failed, 'has-bar': percent !== null }"
        :title="name"
    >
        <span v-if="running && percent === null" class="ai-kit-tool-chip__spinner" aria-hidden="true" />
        <span v-else-if="!running" class="ai-kit-tool-chip__mark" aria-hidden="true">{{ failed ? '✗' : '✓' }}</span>
        <span class="ai-kit-tool-chip__name" dir="auto">{{ name }}</span>
        <span v-if="live?.label" class="ai-kit-tool-chip__label" dir="auto">{{ live.label }}</span>
        <span v-if="count !== null" class="ai-kit-tool-chip__count" dir="ltr">{{ count }}</span>
        <span
            v-if="percent !== null"
            class="ai-kit-tool-chip__bar"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="Math.round(percent)"
        >
            <span class="ai-kit-tool-chip__fill" :style="{ width: `${percent}%` }" />
        </span>
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

.ai-kit-tool-chip__label {
    opacity: var(--ai-kit-thinking-opacity, 0.85);
    unicode-bidi: isolate;
}

.ai-kit-tool-chip__count {
    font-variant-numeric: tabular-nums;
    /* The digits and their slash stay LTR whatever the host's direction — an
       Arabic sentence must not turn `12/40` into `40/12`. */
    unicode-bidi: isolate;
    opacity: var(--ai-kit-thinking-opacity, 0.85);
}

.ai-kit-tool-chip__bar {
    flex: none;
    width: 3rem;
    height: 0.25rem;
    border-radius: 999px;
    background: color-mix(in oklab, currentColor 18%, transparent);
    overflow: hidden;
}

.ai-kit-tool-chip__fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--ai-kit-progress, var(--ai-kit-accent, #3b82f6));
    transition: width 0.2s ease-out;
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

    .ai-kit-tool-chip__fill {
        transition: none;
    }
}
</style>

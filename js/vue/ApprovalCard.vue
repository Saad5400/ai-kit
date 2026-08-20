<script setup lang="ts">
/**
 * The full chrome of an approval card: header (icon, title, status badge),
 * reason, preview lines, the form, and the decision buttons — so an app gets a
 * card that looks finished without writing any of it, and themes it through
 * `--ai-kit-*` variables rather than by forking the markup. Mirror of
 * `js/svelte/ApprovalCard.svelte`.
 *
 * REDESIGNED IN v0.9.0 from prod screenshots (owner ruling #22 — "this is
 * bad"). Every defect in that screenshot and where it went:
 *
 *   - The title rendered TWICE: once bold in the header, once as a preview
 *     line, because the tool's `preview()` returns the same sentence as its
 *     `title()`. `previewLines()` drops a line that only repeats the title (see
 *     `js/core/cards.ts`), so one title is rendered once.
 *   - Raw `name` / `track_id` / `chapter_id` labels and an internal id
 *     (`v6oPvGqX`) as a headline value. Labels are humanized when a tool
 *     declared none, and readonly identity fields collapse into a details
 *     disclosure — see `ApprovalFields`.
 *   - An empty value rendered as a bare "–". It is a localized `emptyLabel`
 *     now.
 *   - «طبّق» / «تجاهل» looked like plain text and the pending chip like text
 *     floating in the row. Confirm is a filled primary button, reject a quiet
 *     bordered tertiary one, and the chip a real badge with a dot.
 *   - The title's Latin fragments scrambled inside the Arabic header. The
 *     title, the reason, every preview line and the badge are
 *     `<bdi dir="auto">`.
 *
 * There is no separate "edit" button: the form IS the edit affordance, and
 * confirming an editable card sends the form as an `{action: 'edit'}` decision
 * (`decisionFor()`), so an untouched form and a changed one travel the same
 * audited path.
 *
 * COLORS THROUGH LONGHANDS, never the `border` / `outline` shorthand. A
 * `--ai-kit-*` token mapped to something that is not a color (raw HSL channels,
 * the shadcn-v3 convention: `--border: 240 4% 16%`) makes the whole shorthand
 * invalid at computed-value time — which is how the prod card lost every border
 * and fill at once. Split into `border-color` / `border-width`, the same
 * mapping degrades to `currentColor` and the card still reads.
 *
 * The `decide` event carries the payload `ResumeDecisions::fromClient()` accepts
 * for this call, so a consumer's handler is one request:
 *
 *     <ApprovalCard :card="card" @decide="(d) => post({ [card.id]: d })" />
 *
 * A destructive card is one-click by construction: the server sent every field
 * readonly and `editable: false`, so there is nothing to edit and `decide`
 * emits a bare approve. It also carries the destructive accent on its border and
 * confirm button — the visual weight is derived from the same server flag as the
 * behaviour, never from app-side guessing.
 *
 * Slots: `icon` (leading glyph), `field` (forwarded to `ApprovalFields` for
 * per-widget editors), `preview` (replace the default preview lines).
 *
 * Every user-facing string is a prop, defaulting to Arabic — the fleet is
 * Arabic-first and both consumers render Arabic threads.
 */
import { computed, ref } from 'vue'
import type { ApprovalPayload, ClientDecision } from '../core/events'
import { previewLines } from '../core/cards'
import { decisionFor } from '../core/fields'
import ApprovalFields from './ApprovalFields.vue'

const props = withDefaults(
    defineProps<{
        card: ApprovalPayload
        /** Set while a decision is in flight — both buttons lock. */
        disabled?: boolean
        /**
         * Whether this card is still awaiting a decision. A pending card takes
         * the accent rail that makes it unmissable; pass `false` for a
         * historical card rendered from a persisted thread.
         */
        pending?: boolean
        confirmLabel?: string
        rejectLabel?: string
        /** Status badge copy; pass null to drop the badge. */
        destructiveLabel?: string | null
        undoableLabel?: string | null
        /**
         * Overrides the non-destructive badge while pending — for apps that
         * would rather say "awaiting your approval" than "undoable".
         */
        pendingLabel?: string | null
        /** Toggle copy for the technical-fields disclosure. */
        detailsLabel?: string
        /** Stands in for a value the model left out. */
        emptyLabel?: string
    }>(),
    {
        disabled: false,
        pending: true,
        confirmLabel: 'تأكيد',
        rejectLabel: 'رفض',
        destructiveLabel: 'لا يمكن التراجع',
        undoableLabel: 'قابل للتراجع',
        pendingLabel: null,
        detailsLabel: 'تفاصيل تقنية',
        emptyLabel: 'غير محدَّد',
    },
)

const emit = defineEmits<{
    decide: [decision: ClientDecision]
}>()

const edits = ref<Record<string, unknown>>({})

const badge = computed(() =>
    props.card.destructive
        ? props.destructiveLabel
        : props.pending
          ? (props.pendingLabel ?? props.undoableLabel)
          : props.undoableLabel,
)

const lines = computed(() => previewLines(props.card))

const hasForm = computed(() => props.card.fields.some((field) => field.widget !== 'hidden'))
</script>

<template>
    <section class="ai-kit-card" :class="{ 'is-destructive': card.destructive, 'is-pending': pending }">
        <header class="ai-kit-card__header">
            <span class="ai-kit-card__icon">
                <slot name="icon" />
            </span>
            <h3 class="ai-kit-card__title"><bdi dir="auto">{{ card.title }}</bdi></h3>
            <span v-if="badge" class="ai-kit-card__badge" :class="{ 'is-destructive': card.destructive }">
                <span class="ai-kit-card__dot" aria-hidden="true" />
                <bdi dir="auto">{{ badge }}</bdi>
            </span>
        </header>

        <p v-if="card.reason" class="ai-kit-card__reason"><bdi dir="auto">{{ card.reason }}</bdi></p>

        <slot name="preview" :lines="lines">
            <ul v-if="lines.length" class="ai-kit-card__preview">
                <li v-for="(line, index) in lines" :key="index"><bdi dir="auto">{{ line }}</bdi></li>
            </ul>
        </slot>

        <ApprovalFields
            v-if="hasForm"
            :fields="card.fields"
            :disabled="disabled"
            :details-label="detailsLabel"
            :empty-label="emptyLabel"
            @update="(values) => (edits = values)"
        >
            <template v-if="$slots.field" #field="slotProps">
                <slot name="field" v-bind="slotProps" />
            </template>
        </ApprovalFields>

        <!-- Confirm first in reading order, so RTL puts it on the right. -->
        <footer class="ai-kit-card__actions">
            <button
                type="button"
                class="ai-kit-card__confirm"
                :disabled="disabled"
                @click="emit('decide', decisionFor(card, edits))"
            >
                <bdi dir="auto">{{ confirmLabel }}</bdi>
            </button>
            <button
                type="button"
                class="ai-kit-card__reject"
                :disabled="disabled"
                @click="emit('decide', { action: 'reject' })"
            >
                <bdi dir="auto">{{ rejectLabel }}</bdi>
            </button>
        </footer>
    </section>
</template>

<style scoped>
.ai-kit-card {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0.875rem;
    border-width: 1px;
    border-style: solid;
    border-color: var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: var(--ai-kit-surface, transparent);
    /*
     * A static tint ON TOP of the mapped surface, so the card separates from
     * whatever it sits on even when an app maps `--ai-kit-surface` to the page
     * background (uqucc does) or to something invalid (catodemy did) — the
     * "buried inside the thinking steps" complaint.
     */
    background-image: linear-gradient(
        color-mix(in oklab, currentColor 5%, transparent),
        color-mix(in oklab, currentColor 5%, transparent)
    );
    text-align: start;
}

/* Unmissable: an accent rail on the inline-start edge, logical so Arabic needs
   no mirrored rule. */
.ai-kit-card.is-pending {
    border-inline-start-width: 3px;
    border-inline-start-color: var(--ai-kit-accent, #3b82f6);
}

.ai-kit-card.is-destructive {
    border-color: var(--ai-kit-destructive, #ef4444);
    background-image: linear-gradient(
        color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 10%, transparent),
        color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 10%, transparent)
    );
}

.ai-kit-card.is-destructive.is-pending {
    border-inline-start-color: var(--ai-kit-destructive, #ef4444);
}

.ai-kit-card__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.ai-kit-card__icon:empty {
    display: none;
}

.ai-kit-card__title {
    margin: 0;
    font-size: 1em;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
    /* An LTR tool title inside an RTL header must not reorder the badge — the
       `<bdi>` inside does the isolating, this is the belt. */
    unicode-bidi: isolate;
}

.ai-kit-card__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3125rem;
    margin-inline-start: auto;
    padding: 0.125rem 0.5rem;
    border-width: 1px;
    border-style: solid;
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 25%, transparent));
    border-radius: 999px;
    /* Its OWN token: an app that maps `--ai-kit-surface` to the card's own
       background — as both consumers do — left this badge invisible, which is
       the "just text floating in the row" complaint. */
    background-color: var(--ai-kit-badge-bg, color-mix(in oklab, currentColor 10%, transparent));
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 75%, transparent));
    font-size: var(--ai-kit-label-size, 0.75rem);
    font-weight: 500;
    white-space: nowrap;
    unicode-bidi: isolate;
}

.ai-kit-card__badge.is-destructive {
    border-color: var(--ai-kit-destructive, #ef4444);
    background-color: color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 18%, transparent);
    color: var(--ai-kit-destructive, #ef4444);
}

.ai-kit-card__dot {
    inline-size: 0.375rem;
    block-size: 0.375rem;
    flex: none;
    border-radius: 999px;
    background-color: currentColor;
}

.ai-kit-card__reason {
    margin: 0;
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 70%, transparent));
    font-size: var(--ai-kit-label-size, 0.8125rem);
    line-height: 1.6;
}

.ai-kit-card__preview {
    margin: 0;
    padding-inline-start: 1.125rem;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    font-size: 0.875rem;
    line-height: 1.6;
}

.ai-kit-card__actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-block-start: 0.125rem;
}

.ai-kit-card__confirm,
.ai-kit-card__reject {
    padding: 0.4375rem 1rem;
    border-width: 1px;
    border-style: solid;
    border-radius: var(--ai-kit-radius, 0.5rem);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}

.ai-kit-card__confirm {
    /* Accent on the border as well as the fill: if a broken token collapses the
       background, the button still reads as the primary control. */
    border-color: var(--ai-kit-accent, #3b82f6);
    background-color: var(--ai-kit-accent, #3b82f6);
    color: var(--ai-kit-accent-fg, #fff);
}

.ai-kit-card.is-destructive .ai-kit-card__confirm {
    border-color: var(--ai-kit-destructive, #ef4444);
    background-color: var(--ai-kit-destructive, #ef4444);
    color: var(--ai-kit-destructive-fg, #fff);
}

.ai-kit-card__reject {
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 30%, transparent));
    background-color: transparent;
    color: var(--ai-kit-muted, inherit);
    font-weight: 500;
}

.ai-kit-card__reject:hover:not(:disabled) {
    border-color: var(--ai-kit-destructive, #ef4444);
    background-color: color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 10%, transparent);
    color: var(--ai-kit-destructive, inherit);
}

.ai-kit-card__confirm:disabled,
.ai-kit-card__reject:disabled {
    cursor: default;
    opacity: 0.5;
}

.ai-kit-card__confirm:focus-visible,
.ai-kit-card__reject:focus-visible {
    outline-width: 2px;
    outline-style: solid;
    outline-color: var(--ai-kit-accent, #3b82f6);
    outline-offset: 2px;
}
</style>

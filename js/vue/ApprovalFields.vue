<script setup lang="ts">
/**
 * An approval card's editable form, rendered from the SERVER's field schema
 * (`ApprovalPayload.fields`) instead of guessed from raw arguments. Mirror of
 * `js/svelte/ApprovalFields.svelte`.
 *
 * Per widget: `hidden` is skipped entirely, `readonly` renders as a definition
 * row (muted label, value as text — never a disabled input), the rest render
 * their matching control. `textarea` / `markdown` / `code` get an auto-growing
 * editor that scrolls internally past ~40vh rather than a single-line input,
 * with a character count, because the argument that provoked this was a
 * 1,733-character markdown body in one `<input>`.
 *
 * TWO TIERS SINCE v0.9.0 (owner ruling #22). The prod card led with
 * `track_id: v6oPvGqX` and `chapter_id: –` and buried the one argument the user
 * actually had to read. Readonly IDENTITY fields (`id`, `*_id`, `*_uuid` — see
 * `isIdentityField()`) now collapse into a `<details>` disclosure under
 * `detailsLabel`: still there for anyone auditing what the write addresses, out
 * of the way of the decision. Everything else stays a headline row in the
 * tool's declared order. Everything in that half is `readonly` by
 * construction, which is why the disclosure renders definition rows only.
 *
 * LABELS. A tool that labels its arguments —
 * `Field::make('body', FieldWidget::Markdown, label: 'المحتوى')` — gets that
 * label verbatim, in the conversation's language, and that is the fix worth
 * making app-side. An unlabelled argument is HUMANIZED rather than printed raw
 * (`track_id` → `Track`), because `name` / `track_id` / `chapter_id` as
 * headline labels is what the owner called out.
 *
 * BIDI. Labels, values and the disclosure toggle are `<bdi dir="auto">`, so a
 * Latin fragment inside an Arabic card cannot reorder the line — dropping
 * `action` into an RTL line unisolated is what rendered `action: create` as a
 * scrambled "create action:". A value that looks like a machine token (an id,
 * an enum member, a path) additionally renders mono and `dir="ltr"`.
 *
 * An empty value renders `emptyLabel` — a localized "not set", never a bare
 * dash, which told an Arabic reader nothing about whether the field was empty,
 * unknown or broken.
 *
 * Substitute a real editor with the `field` slot, which receives every field so
 * an app can switch on `field.widget`:
 *
 *     <ApprovalFields :fields="card.fields" @update="stage">
 *         <template #field="{ field, value, update }">
 *             <MyEditor v-if="field.widget === 'markdown'" :value="value" @change="update" />
 *         </template>
 *     </ApprovalFields>
 *
 * `update` receives the full map of EDITABLE field values — the arguments of an
 * `{action: 'edit'}` decision. Readonly and hidden fields are deliberately
 * absent: the server restores those from the pending call
 * (`ApprovalCards::guardEdits()`), which is what makes the form safe rather
 * than these flags.
 */
import { computed, ref } from 'vue'
import type { ApprovalCardField } from '../core/events'
import {
    displayValue,
    editableArguments,
    fieldLabel,
    isLongText,
    isMachineValue,
    isMono,
    partitionFields,
} from '../core/fields'

const props = withDefaults(
    defineProps<{
        fields: ApprovalCardField[]
        /** Set while a decision is in flight, so a double submit cannot edit. */
        disabled?: boolean
        /** Toggle copy for the identity-fields disclosure. */
        detailsLabel?: string
        /** Stands in for a value the model left out. */
        emptyLabel?: string
    }>(),
    {
        disabled: false,
        detailsLabel: 'تفاصيل تقنية',
        emptyLabel: 'غير محدَّد',
    },
)

const emit = defineEmits<{
    update: [values: Record<string, unknown>]
}>()

// Seeded once, on purpose: from here the user's edits own the form. A card the
// server repainted is a NEW card — remount with `:key="card.id"`.
const edited = ref<Record<string, unknown>>(
    Object.fromEntries(props.fields.map((field) => [field.name, field.value])),
)

// Hidden fields never render; identity fields render behind the disclosure;
// label copy is resolved once per field rather than three times per branch in
// the template.
const split = computed(() => partitionFields(props.fields))

const rows = computed(() =>
    split.value.rows.map((field) => ({ field, label: fieldLabel(field).text })),
)

const details = computed(() =>
    split.value.details.map((field) => ({ field, label: fieldLabel(field).text })),
)

const valueOf = (field: ApprovalCardField): unknown => edited.value[field.name] ?? field.value

const textOf = (field: ApprovalCardField): string => String(valueOf(field) ?? '')

const update = (field: ApprovalCardField, value: unknown): void => {
    edited.value = { ...edited.value, [field.name]: value }

    emit('update', editableArguments(props.fields, edited.value))
}

const onInput = (field: ApprovalCardField, event: Event): void => {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement

    update(field, field.widget === 'boolean' ? (target as HTMLInputElement).checked : target.value)
}
</script>

<template>
    <div class="ai-kit-fields">
        <template v-for="{ field, label } in rows" :key="field.name">
            <!-- Readonly: a definition row, not a dead input. -->
            <div v-if="field.widget === 'readonly'" class="ai-kit-fields__row is-readonly">
                <span class="ai-kit-fields__label">
                    <bdi dir="auto">{{ label }}</bdi>
                </span>
                <bdi
                    class="ai-kit-fields__value"
                    :class="{ 'is-machine': isMachineValue(field.value) }"
                    :dir="isMachineValue(field.value) ? 'ltr' : 'auto'"
                >{{ displayValue(field.value, emptyLabel) }}</bdi>
            </div>

            <!-- Boolean: the control belongs beside its own label. -->
            <label v-else-if="field.widget === 'boolean'" class="ai-kit-fields__row is-inline">
                <slot name="field" :field="field" :value="valueOf(field)" :update="(value: unknown) => update(field, value)">
                    <input
                        type="checkbox"
                        class="ai-kit-fields__check"
                        :checked="Boolean(valueOf(field))"
                        :disabled="disabled"
                        @change="onInput(field, $event)"
                    />
                </slot>
                <span class="ai-kit-fields__label">
                    <bdi dir="auto">{{ label }}</bdi>
                </span>
            </label>

            <label v-else class="ai-kit-fields__row">
                <span class="ai-kit-fields__label">
                    <bdi dir="auto">{{ label }}</bdi>
                    <span v-if="isLongText(field.widget) && textOf(field).length > 0" class="ai-kit-fields__count" dir="ltr">
                        {{ textOf(field).length }}
                    </span>
                </span>

                <slot name="field" :field="field" :value="valueOf(field)" :update="(value: unknown) => update(field, value)">
                    <input
                        v-if="field.widget === 'number'"
                        type="number"
                        class="ai-kit-fields__input"
                        dir="ltr"
                        :value="valueOf(field)"
                        :placeholder="field.placeholder ?? undefined"
                        :disabled="disabled"
                        @input="onInput(field, $event)"
                    />
                    <select
                        v-else-if="field.widget === 'select'"
                        class="ai-kit-fields__input"
                        dir="auto"
                        :disabled="disabled"
                        @change="onInput(field, $event)"
                    >
                        <option
                            v-for="option in field.options ?? []"
                            :key="String(option.value)"
                            :value="String(option.value)"
                            :selected="String(option.value) === String(valueOf(field))"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <textarea
                        v-else-if="isLongText(field.widget)"
                        class="ai-kit-fields__input ai-kit-fields__area"
                        :class="{ 'is-machine': isMono(field.widget) }"
                        :value="textOf(field)"
                        :placeholder="field.placeholder ?? undefined"
                        :disabled="disabled"
                        :dir="field.widget === 'code' ? 'ltr' : 'auto'"
                        rows="3"
                        @input="onInput(field, $event)"
                    />
                    <input
                        v-else
                        type="text"
                        class="ai-kit-fields__input"
                        dir="auto"
                        :value="valueOf(field)"
                        :placeholder="field.placeholder ?? undefined"
                        :disabled="disabled"
                        @input="onInput(field, $event)"
                    />
                </slot>
            </label>
        </template>

        <!-- The ids the write addresses: auditable, not headline. -->
        <details v-if="details.length" class="ai-kit-fields__details">
            <summary class="ai-kit-fields__summary">
                <svg class="ai-kit-fields__chevron" viewBox="0 0 12 12" aria-hidden="true">
                    <path
                        d="M3 4.5 6 7.5 9 4.5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
                <bdi dir="auto">{{ detailsLabel }}</bdi>
            </summary>
            <div class="ai-kit-fields__details-body">
                <div v-for="{ field, label } in details" :key="field.name" class="ai-kit-fields__row is-readonly">
                    <span class="ai-kit-fields__label">
                        <bdi dir="auto">{{ label }}</bdi>
                    </span>
                    <bdi
                        class="ai-kit-fields__value"
                        :class="{ 'is-machine': isMachineValue(field.value) }"
                        :dir="isMachineValue(field.value) ? 'ltr' : 'auto'"
                    >{{ displayValue(field.value, emptyLabel) }}</bdi>
                </div>
            </div>
        </details>
    </div>
</template>

<style scoped>
.ai-kit-fields {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    text-align: start;
}

.ai-kit-fields__row {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    /* Keeps an LTR token inside the row from reordering the RTL line. */
    unicode-bidi: isolate;
}

.ai-kit-fields__row.is-inline {
    flex-direction: row;
    align-items: center;
    gap: 0.5rem;
}

.ai-kit-fields__label {
    display: flex;
    align-items: baseline;
    gap: 0.375rem;
    font-size: var(--ai-kit-label-size, 0.75rem);
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
}

.ai-kit-fields__count {
    margin-inline-start: auto;
    font-variant-numeric: tabular-nums;
    opacity: 0.7;
}

.ai-kit-fields__value {
    overflow-wrap: anywhere;
}

.is-machine {
    font-family: var(--ai-kit-code-font, ui-monospace, SFMono-Regular, Menlo, monospace);
}

.ai-kit-fields__input {
    width: 100%;
    padding: 0.375rem 0.5rem;
    border-width: 1px;
    border-style: solid;
    border-color: var(--ai-kit-control-border, color-mix(in oklab, currentColor 30%, transparent));
    border-radius: var(--ai-kit-radius, 0.5rem);
    background-color: var(--ai-kit-input-bg, color-mix(in oklab, currentColor 6%, transparent));
    color: inherit;
    font: inherit;
    text-align: start;
}

.ai-kit-fields__input:focus-visible,
.ai-kit-fields__summary:focus-visible {
    outline-width: 2px;
    outline-style: solid;
    outline-color: var(--ai-kit-accent, #3b82f6);
    outline-offset: 1px;
}

.ai-kit-fields__check {
    accent-color: var(--ai-kit-accent, #3b82f6);
}

/*
 * Grows with its content instead of stranding a long body in three lines, then
 * scrolls internally rather than pushing the buttons off-screen.
 */
.ai-kit-fields__area {
    field-sizing: content;
    min-height: 4.5rem;
    max-height: 40vh;
    overflow-y: auto;
    resize: vertical;
    line-height: 1.6;
}

.ai-kit-fields__area.is-machine {
    font-size: var(--ai-kit-code-size, 0.8125rem);
}

.ai-kit-fields__summary {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    width: fit-content;
    padding: 0.125rem 0.375rem;
    margin-inline-start: -0.375rem;
    border-radius: var(--ai-kit-radius, 0.5rem);
    color: var(--ai-kit-muted, color-mix(in oklab, currentColor 65%, transparent));
    font-size: var(--ai-kit-label-size, 0.75rem);
    cursor: pointer;
    list-style: none;
    user-select: none;
}

/* Safari still paints its own triangle without this. */
.ai-kit-fields__summary::-webkit-details-marker {
    display: none;
}

.ai-kit-fields__summary:hover {
    background-color: var(--ai-kit-hover, color-mix(in oklab, currentColor 8%, transparent));
}

/* Down when open, up when closed — direction-neutral, so RTL needs no mirror. */
.ai-kit-fields__chevron {
    width: 0.75em;
    height: 0.75em;
    flex: none;
    transform: rotate(180deg);
}

.ai-kit-fields__details[open] .ai-kit-fields__chevron {
    transform: rotate(0deg);
}

.ai-kit-fields__details-body {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    margin-block-start: 0.375rem;
    padding-inline-start: 0.625rem;
    border-inline-start-width: 2px;
    border-inline-start-style: solid;
    border-inline-start-color: var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
}
</style>

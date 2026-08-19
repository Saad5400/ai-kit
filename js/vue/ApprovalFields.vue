<script setup lang="ts">
/**
 * An approval card's editable form, rendered from the SERVER's field schema
 * (`ApprovalPayload.fields`) instead of guessed from raw arguments — the fix
 * for confirm dialogs that offered every argument, ids included, as a plain
 * text input.
 *
 * Per widget: `hidden` is skipped entirely, `readonly` renders as a definition
 * row (muted label, value as text — never a disabled input), the rest render
 * their matching control. `textarea` / `markdown` / `code` get an auto-growing
 * editor that scrolls internally past ~40vh rather than a single-line input,
 * with a character count, because the argument that provoked this was a
 * 1,733-character markdown body in one `<input>`.
 *
 * BIDI. A raw argument name is a machine token, so it renders mono, `dir="ltr"`
 * and bidi-ISOLATED — dropping `action` into an RTL line unisolated is what
 * rendered `action: create` as a scrambled "create action:". A tool that gave
 * the field its own `label` gets ordinary `dir="auto"` prose instead. Readonly
 * values are isolated the same way when they look like ids or enum members.
 *
 * Substitute a real editor with the `field` slot, which receives every field so
 * an app can switch on `field.widget` and fall through for the rest:
 *
 *     <ApprovalFields :fields="card.fields" @update="onUpdate">
 *         <template #field="{ field, value, update }">
 *             <MyMarkdownEditor v-if="field.widget === 'markdown'"
 *                 :model-value="value" @update:model-value="update" />
 *         </template>
 *     </ApprovalFields>
 *
 * `update` emits the full map of EDITABLE field values — the arguments of an
 * `{action: 'edit'}` decision. Readonly and hidden fields are deliberately
 * absent: the server restores those from the pending call anyway
 * (`ApprovalCards::guardEdits()`), which is what makes the form safe rather
 * than these flags.
 */
import { computed, ref } from 'vue'
import type { ApprovalCardField } from '../core/events'
import { displayValue, editableArguments, fieldLabel, isLongText, isMachineValue, isMono } from '../core/fields'

const props = defineProps<{
    fields: ApprovalCardField[]
    /** Set while a decision is in flight, so a double submit cannot edit. */
    disabled?: boolean
}>()

const emit = defineEmits<{
    update: [values: Record<string, unknown>]
}>()

// Seeded once, on purpose: from here the user's edits own the form. A card the
// server repainted is a NEW card — remount with `:key="card.id"`.
const edited = ref<Record<string, unknown>>(
    Object.fromEntries(props.fields.map((field) => [field.name, field.value])),
)

// Hidden fields never render; label copy is resolved once per field rather
// than three times per branch in the template.
const rows = computed(() =>
    props.fields
        .filter((field) => field.widget !== 'hidden')
        .map((field) => ({ field, label: fieldLabel(field) })),
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
                    <bdi :class="{ 'is-machine': label.machine }" :dir="label.machine ? 'ltr' : 'auto'">{{ label.text }}</bdi>
                </span>
                <bdi
                    class="ai-kit-fields__value"
                    :class="{ 'is-machine': isMachineValue(field.value) }"
                    :dir="isMachineValue(field.value) ? 'ltr' : 'auto'"
                >{{ displayValue(field.value) }}</bdi>
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
                    <bdi :class="{ 'is-machine': label.machine }" :dir="label.machine ? 'ltr' : 'auto'">{{ label.text }}</bdi>
                </span>
            </label>

            <label v-else class="ai-kit-fields__row">
                <span class="ai-kit-fields__label">
                    <bdi :class="{ 'is-machine': label.machine }" :dir="label.machine ? 'ltr' : 'auto'">{{ label.text }}</bdi>
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
    </div>
</template>

<style scoped>
.ai-kit-fields {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
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
    padding: 0.3125rem 0.5rem;
    border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
    border-radius: var(--ai-kit-radius, 0.5rem);
    background: var(--ai-kit-input-bg, color-mix(in oklab, currentColor 4%, transparent));
    color: inherit;
    font: inherit;
}

.ai-kit-fields__input:focus-visible {
    outline: 2px solid var(--ai-kit-accent, #3b82f6);
    outline-offset: 1px;
}

.ai-kit-fields__check {
    accent-color: var(--ai-kit-accent, #3b82f6);
}

/*
 * Grows with its content instead of stranding a long body in three lines, then
 * scrolls internally rather than pushing the decision buttons off-screen.
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
</style>

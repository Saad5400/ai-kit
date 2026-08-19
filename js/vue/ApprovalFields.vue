<script setup lang="ts">
/**
 * An approval card's editable form, rendered from the SERVER's field schema
 * (`ApprovalPayload.fields`) instead of guessed from raw arguments — the fix
 * for confirm dialogs that offered every argument, ids included, as a plain
 * text input.
 *
 * Per widget: `hidden` is skipped entirely, `readonly` renders as a label and
 * a value (never an input), the rest render their matching control.
 * `markdown` and `code` get a monospace auto-growing textarea by default;
 * substitute a real editor with the `field` slot, which receives every field
 * so an app can switch on `field.widget` and fall through for the rest:
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
import { editableArguments } from '../core/events'
import type { ApprovalCardField } from '../core/events'

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

const visible = computed(() => props.fields.filter((field) => field.widget !== 'hidden'))

const valueOf = (field: ApprovalCardField): unknown => edited.value[field.name] ?? field.value

const update = (field: ApprovalCardField, value: unknown): void => {
    edited.value = { ...edited.value, [field.name]: value }

    emit('update', editableArguments(props.fields, edited.value))
}

const onInput = (field: ApprovalCardField, event: Event): void => {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement

    update(field, field.widget === 'boolean' ? (target as HTMLInputElement).checked : target.value)
}

const labelOf = (field: ApprovalCardField): string => field.label ?? field.name

const display = (value: unknown): string =>
    value === null || value === undefined
        ? '—'
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value)
</script>

<template>
    <div class="ai-kit-fields">
        <div v-for="field in visible" :key="field.name" class="ai-kit-fields__row">
            <span class="ai-kit-fields__label" dir="auto">{{ labelOf(field) }}</span>

            <span v-if="field.widget === 'readonly'" class="ai-kit-fields__value" dir="auto">
                {{ display(field.value) }}
            </span>

            <slot v-else name="field" :field="field" :value="valueOf(field)" :update="(value: unknown) => update(field, value)">
                <input
                    v-if="field.widget === 'boolean'"
                    type="checkbox"
                    class="ai-kit-fields__check"
                    :checked="Boolean(valueOf(field))"
                    :disabled="disabled"
                    @change="onInput(field, $event)"
                />
                <input
                    v-else-if="field.widget === 'number'"
                    type="number"
                    class="ai-kit-fields__input"
                    :value="valueOf(field)"
                    :placeholder="field.placeholder ?? undefined"
                    :disabled="disabled"
                    @input="onInput(field, $event)"
                />
                <select
                    v-else-if="field.widget === 'select'"
                    class="ai-kit-fields__input"
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
                    v-else-if="field.widget !== 'text'"
                    class="ai-kit-fields__input ai-kit-fields__area"
                    :class="{ 'is-mono': field.widget === 'markdown' || field.widget === 'code' }"
                    :value="String(valueOf(field) ?? '')"
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
        </div>
    </div>
</template>

<style scoped>
.ai-kit-fields {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.ai-kit-fields__row {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.ai-kit-fields__label {
    font-size: var(--ai-kit-label-size, 0.75rem);
    color: var(--ai-kit-muted, #6b7280);
}

.ai-kit-fields__value {
    overflow-wrap: anywhere;
}

.ai-kit-fields__input {
    width: 100%;
    padding: 0.25rem 0.5rem;
    border: 1px solid var(--ai-kit-border, rgba(107, 114, 128, 0.4));
    border-radius: var(--ai-kit-radius, 0.375rem);
    background: var(--ai-kit-input-bg, transparent);
    color: inherit;
    font: inherit;
}

.ai-kit-fields__check {
    align-self: flex-start;
}

/* Grows with its content so a long body is not edited through a 3-line slot. */
.ai-kit-fields__area {
    field-sizing: content;
    min-height: 4.5rem;
    max-height: 60vh;
    resize: vertical;
}

.ai-kit-fields__area.is-mono {
    font-family: var(--ai-kit-code-font, ui-monospace, SFMono-Regular, Menlo, monospace);
    font-size: var(--ai-kit-code-size, 0.8125rem);
}
</style>

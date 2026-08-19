<script lang="ts">
    /**
     * An approval card's editable form, rendered from the SERVER's field
     * schema (`ApprovalPayload.fields`) instead of guessed from raw arguments.
     * Mirror of `js/vue/ApprovalFields.vue`.
     *
     * Per widget: `hidden` is skipped entirely, `readonly` renders as a label
     * and a value (never an input), the rest render their matching control.
     * `markdown` and `code` get a monospace auto-growing textarea by default;
     * substitute a real editor with the `field` snippet, which receives every
     * field so an app can switch on `field.widget`:
     *
     *     <ApprovalFields fields={card.fields} onupdate={submit}>
     *         {#snippet field({ field, value, update })}
     *             {#if field.widget === 'markdown'}
     *                 <MyEditor {value} onchange={update} />
     *             {/if}
     *         {/snippet}
     *     </ApprovalFields>
     *
     * `onupdate` receives the full map of EDITABLE field values — the
     * arguments of an `{action: 'edit'}` decision. Readonly and hidden fields
     * are deliberately absent: the server restores those from the pending call
     * (`ApprovalCards::guardEdits()`), which is what makes the form safe
     * rather than these flags.
     */
    import { untrack, type Snippet } from 'svelte'
    import { editableArguments } from '../core/events'
    import type { ApprovalCardField } from '../core/events'

    let {
        fields,
        disabled = false,
        onupdate,
        field: fieldSnippet,
    }: {
        fields: ApprovalCardField[]
        /** Set while a decision is in flight, so a double submit cannot edit. */
        disabled?: boolean
        onupdate?: (values: Record<string, unknown>) => void
        field?: Snippet<[{ field: ApprovalCardField; value: unknown; update: (value: unknown) => void }]>
    } = $props()

    // Seeded once, on purpose: from here the user's edits own the form. A card
    // the server repainted is a NEW card — remount with `{#key card.id}`.
    let edited = $state<Record<string, unknown>>(
        untrack(() => Object.fromEntries(fields.map((entry) => [entry.name, entry.value]))),
    )

    const visible = $derived(fields.filter((entry) => entry.widget !== 'hidden'))

    const valueOf = (entry: ApprovalCardField): unknown => edited[entry.name] ?? entry.value

    function update(entry: ApprovalCardField, value: unknown): void {
        edited[entry.name] = value

        onupdate?.(editableArguments(fields, edited))
    }

    function onInput(entry: ApprovalCardField, event: Event): void {
        const target = event.currentTarget as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement

        update(entry, entry.widget === 'boolean' ? (target as HTMLInputElement).checked : target.value)
    }

    const display = (value: unknown): string =>
        value === null || value === undefined
            ? '—'
            : typeof value === 'object'
              ? JSON.stringify(value)
              : String(value)
</script>

<div class="ai-kit-fields">
    {#each visible as entry (entry.name)}
        <div class="ai-kit-fields__row">
            <span class="ai-kit-fields__label" dir="auto">{entry.label ?? entry.name}</span>

            {#if entry.widget === 'readonly'}
                <span class="ai-kit-fields__value" dir="auto">{display(entry.value)}</span>
            {:else if fieldSnippet}
                {@render fieldSnippet({
                    field: entry,
                    value: valueOf(entry),
                    update: (value: unknown) => update(entry, value),
                })}
            {:else if entry.widget === 'boolean'}
                <input
                    type="checkbox"
                    class="ai-kit-fields__check"
                    checked={Boolean(valueOf(entry))}
                    {disabled}
                    onchange={(event) => onInput(entry, event)}
                />
            {:else if entry.widget === 'number'}
                <input
                    type="number"
                    class="ai-kit-fields__input"
                    value={valueOf(entry)}
                    placeholder={entry.placeholder ?? undefined}
                    {disabled}
                    oninput={(event) => onInput(entry, event)}
                />
            {:else if entry.widget === 'select'}
                <select
                    class="ai-kit-fields__input"
                    {disabled}
                    onchange={(event) => onInput(entry, event)}
                >
                    {#each entry.options ?? [] as option (String(option.value))}
                        <option
                            value={String(option.value)}
                            selected={String(option.value) === String(valueOf(entry))}
                        >
                            {option.label}
                        </option>
                    {/each}
                </select>
            {:else if entry.widget === 'text'}
                <input
                    type="text"
                    class="ai-kit-fields__input"
                    dir="auto"
                    value={valueOf(entry)}
                    placeholder={entry.placeholder ?? undefined}
                    {disabled}
                    oninput={(event) => onInput(entry, event)}
                />
            {:else}
                <textarea
                    class="ai-kit-fields__input ai-kit-fields__area"
                    class:is-mono={entry.widget === 'markdown' || entry.widget === 'code'}
                    dir={entry.widget === 'code' ? 'ltr' : 'auto'}
                    value={String(valueOf(entry) ?? '')}
                    placeholder={entry.placeholder ?? undefined}
                    rows="3"
                    {disabled}
                    oninput={(event) => onInput(entry, event)}
                ></textarea>
            {/if}
        </div>
    {/each}
</div>

<style>
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

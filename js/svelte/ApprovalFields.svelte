<script lang="ts">
    /**
     * An approval card's editable form, rendered from the SERVER's field
     * schema (`ApprovalPayload.fields`) instead of guessed from raw arguments.
     * Mirror of `js/vue/ApprovalFields.vue`.
     *
     * Per widget: `hidden` is skipped entirely, `readonly` renders as a
     * definition row (muted label, value as text — never a disabled input), the
     * rest render their matching control. `textarea` / `markdown` / `code` get
     * an auto-growing editor that scrolls internally past ~40vh rather than a
     * single-line input, with a character count, because the argument that
     * provoked this was a 1,733-character markdown body in one `<input>`.
     *
     * BIDI. A raw argument name is a machine token, so it renders mono,
     * `dir="ltr"` and bidi-ISOLATED — dropping `action` into an RTL line
     * unisolated is what rendered `action: create` as a scrambled
     * "create action:". A tool that gave the field its own `label` gets
     * ordinary `dir="auto"` prose. Readonly values are isolated the same way
     * when they look like ids or enum members.
     *
     * Substitute a real editor with the `field` snippet, which receives every
     * field so an app can switch on `field.widget`:
     *
     *     <ApprovalFields fields={card.fields} onupdate={stage}>
     *         {#snippet field({ field, value, update })}
     *             {#if field.widget === 'markdown'}
     *                 <MyEditor {value} onchange={update} />
     *             {/if}
     *         {/snippet}
     *     </ApprovalFields>
     *
     * `onupdate` receives the full map of EDITABLE field values — the arguments
     * of an `{action: 'edit'}` decision. Readonly and hidden fields are
     * deliberately absent: the server restores those from the pending call
     * (`ApprovalCards::guardEdits()`), which is what makes the form safe rather
     * than these flags.
     */
    import { untrack, type Snippet } from 'svelte'
    import type { ApprovalCardField } from '../core/events'
    import {
        displayValue,
        editableArguments,
        fieldLabel,
        isLongText,
        isMachineValue,
        isMono,
    } from '../core/fields'

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

    // Hidden fields never render; label copy is resolved once per field.
    const rows = $derived(
        fields
            .filter((entry) => entry.widget !== 'hidden')
            .map((entry) => ({ entry, label: fieldLabel(entry) })),
    )

    const valueOf = (entry: ApprovalCardField): unknown => edited[entry.name] ?? entry.value

    const textOf = (entry: ApprovalCardField): string => String(valueOf(entry) ?? '')

    function update(entry: ApprovalCardField, value: unknown): void {
        edited[entry.name] = value

        onupdate?.(editableArguments(fields, edited))
    }

    function onInput(entry: ApprovalCardField, event: Event): void {
        const target = event.currentTarget as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement

        update(entry, entry.widget === 'boolean' ? (target as HTMLInputElement).checked : target.value)
    }
</script>

<div class="ai-kit-fields">
    {#each rows as { entry, label } (entry.name)}
        {#if entry.widget === 'readonly'}
            <!-- Readonly: a definition row, not a dead input. -->
            <div class="ai-kit-fields__row is-readonly">
                <span class="ai-kit-fields__label">
                    <bdi class:is-machine={label.machine} dir={label.machine ? 'ltr' : 'auto'}>{label.text}</bdi>
                </span>
                <bdi
                    class="ai-kit-fields__value"
                    class:is-machine={isMachineValue(entry.value)}
                    dir={isMachineValue(entry.value) ? 'ltr' : 'auto'}
                >{displayValue(entry.value)}</bdi>
            </div>
        {:else if entry.widget === 'boolean'}
            <!-- Boolean: the control belongs beside its own label. -->
            <label class="ai-kit-fields__row is-inline">
                {#if fieldSnippet}
                    {@render fieldSnippet({ field: entry, value: valueOf(entry), update: (value) => update(entry, value) })}
                {:else}
                    <input
                        type="checkbox"
                        class="ai-kit-fields__check"
                        checked={Boolean(valueOf(entry))}
                        {disabled}
                        onchange={(event) => onInput(entry, event)}
                    />
                {/if}
                <span class="ai-kit-fields__label">
                    <bdi class:is-machine={label.machine} dir={label.machine ? 'ltr' : 'auto'}>{label.text}</bdi>
                </span>
            </label>
        {:else}
            <label class="ai-kit-fields__row">
                <span class="ai-kit-fields__label">
                    <bdi class:is-machine={label.machine} dir={label.machine ? 'ltr' : 'auto'}>{label.text}</bdi>
                    {#if isLongText(entry.widget) && textOf(entry).length > 0}
                        <span class="ai-kit-fields__count" dir="ltr">{textOf(entry).length}</span>
                    {/if}
                </span>

                {#if fieldSnippet}
                    {@render fieldSnippet({ field: entry, value: valueOf(entry), update: (value) => update(entry, value) })}
                {:else if entry.widget === 'number'}
                    <input
                        type="number"
                        class="ai-kit-fields__input"
                        dir="ltr"
                        value={valueOf(entry)}
                        placeholder={entry.placeholder ?? undefined}
                        {disabled}
                        oninput={(event) => onInput(entry, event)}
                    />
                {:else if entry.widget === 'select'}
                    <select
                        class="ai-kit-fields__input"
                        dir="auto"
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
                {:else if isLongText(entry.widget)}
                    <textarea
                        class="ai-kit-fields__input ai-kit-fields__area"
                        class:is-machine={isMono(entry.widget)}
                        dir={entry.widget === 'code' ? 'ltr' : 'auto'}
                        value={textOf(entry)}
                        placeholder={entry.placeholder ?? undefined}
                        rows="3"
                        {disabled}
                        oninput={(event) => onInput(entry, event)}
                    ></textarea>
                {:else}
                    <input
                        type="text"
                        class="ai-kit-fields__input"
                        dir="auto"
                        value={valueOf(entry)}
                        placeholder={entry.placeholder ?? undefined}
                        {disabled}
                        oninput={(event) => onInput(entry, event)}
                    />
                {/if}
            </label>
        {/if}
    {/each}
</div>

<style>
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
     * Grows with its content instead of stranding a long body in three lines,
     * then scrolls internally rather than pushing the buttons off-screen.
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

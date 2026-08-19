<script lang="ts">
    /**
     * The full chrome of an approval card: header (icon, title, status chip),
     * reason, preview lines, the form, and the decision buttons — so an app gets
     * a card that looks finished without writing any of it, and themes it
     * through `--ai-kit-*` variables rather than by forking the markup. Mirror
     * of `js/vue/ApprovalCard.vue`.
     *
     * `ondecide` carries the payload `ResumeDecisions::fromClient()` accepts for
     * this call, so a consumer's handler is one request:
     *
     *     <ApprovalCard {card} ondecide={(d) => post({ [card.id]: d })} />
     *
     * A destructive card is one-click by construction: the server sent every
     * field readonly and `editable: false`, so there is nothing to edit and
     * `ondecide` emits a bare approve. It also carries the destructive accent on
     * its border and confirm button — the visual weight is derived from the same
     * server flag as the behaviour, never from app-side guessing.
     *
     * Snippets: `icon` (leading glyph), `field` (forwarded to `ApprovalFields`
     * for per-widget editors), `preview` (replace the default preview lines).
     */
    import type { Snippet } from 'svelte'
    import type { ApprovalCardField, ApprovalPayload, ClientDecision } from '../core/events'
    import { decisionFor } from '../core/fields'
    import ApprovalFields from './ApprovalFields.svelte'

    let {
        card,
        disabled = false,
        confirmLabel = 'تأكيد',
        rejectLabel = 'رفض',
        destructiveLabel = 'لا يمكن التراجع',
        undoableLabel = 'قابل للتراجع',
        ondecide,
        icon,
        field,
        preview,
    }: {
        card: ApprovalPayload
        /** Set while a decision is in flight — both buttons lock. */
        disabled?: boolean
        confirmLabel?: string
        rejectLabel?: string
        /** Status chip copy; pass null to drop the chip. */
        destructiveLabel?: string | null
        undoableLabel?: string | null
        ondecide?: (decision: ClientDecision) => void
        icon?: Snippet
        field?: Snippet<[{ field: ApprovalCardField; value: unknown; update: (value: unknown) => void }]>
        preview?: Snippet<[string[]]>
    } = $props()

    let edits = $state<Record<string, unknown>>({})

    const chip = $derived(card.destructive ? destructiveLabel : undoableLabel)

    const previewLines = $derived(
        Array.isArray(card.preview) ? card.preview.map((line) => String(line)) : [],
    )

    const hasForm = $derived(card.fields.some((entry) => entry.widget !== 'hidden'))
</script>

<section class="ai-kit-card" class:is-destructive={card.destructive}>
    <header class="ai-kit-card__header">
        {#if icon}
            <span class="ai-kit-card__icon">{@render icon()}</span>
        {/if}
        <h3 class="ai-kit-card__title" dir="auto">{card.title}</h3>
        {#if chip}
            <span class="ai-kit-card__chip" class:is-destructive={card.destructive} dir="auto">{chip}</span>
        {/if}
    </header>

    {#if card.reason}
        <p class="ai-kit-card__reason" dir="auto">{card.reason}</p>
    {/if}

    {#if preview}
        {@render preview(previewLines)}
    {:else if previewLines.length > 0}
        <ul class="ai-kit-card__preview">
            {#each previewLines as line, index (index)}
                <li dir="auto">{line}</li>
            {/each}
        </ul>
    {/if}

    {#if hasForm}
        <ApprovalFields fields={card.fields} {disabled} {field} onupdate={(values) => (edits = values)} />
    {/if}

    <!-- Confirm first in reading order, so RTL puts it on the right. -->
    <footer class="ai-kit-card__actions">
        <button
            type="button"
            class="ai-kit-card__confirm"
            {disabled}
            onclick={() => ondecide?.(decisionFor(card, edits))}
        >
            {confirmLabel}
        </button>
        <button
            type="button"
            class="ai-kit-card__reject"
            {disabled}
            onclick={() => ondecide?.({ action: 'reject' })}
        >
            {rejectLabel}
        </button>
    </footer>
</section>

<style>
    .ai-kit-card {
        display: flex;
        flex-direction: column;
        gap: 0.625rem;
        padding: 0.875rem;
        border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
        border-radius: var(--ai-kit-radius, 0.5rem);
        background: var(--ai-kit-surface, color-mix(in oklab, currentColor 4%, transparent));
    }

    .ai-kit-card.is-destructive {
        border-color: var(--ai-kit-destructive, #ef4444);
        background: color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 8%, transparent);
    }

    .ai-kit-card__header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .ai-kit-card__title {
        margin: 0;
        font-size: 1em;
        font-weight: 600;
        /* An LTR tool title inside an RTL header must not reorder the chip. */
        unicode-bidi: isolate;
    }

    .ai-kit-card__chip {
        margin-inline-start: auto;
        padding: 0.0625rem 0.5rem;
        border-radius: 999px;
        background: var(--ai-kit-surface, color-mix(in oklab, currentColor 12%, transparent));
        color: var(--ai-kit-muted, color-mix(in oklab, currentColor 70%, transparent));
        font-size: var(--ai-kit-label-size, 0.75rem);
        white-space: nowrap;
    }

    .ai-kit-card__chip.is-destructive {
        background: color-mix(in oklab, var(--ai-kit-destructive, #ef4444) 18%, transparent);
        color: var(--ai-kit-destructive, #ef4444);
    }

    .ai-kit-card__reason {
        margin: 0;
        color: var(--ai-kit-muted, color-mix(in oklab, currentColor 70%, transparent));
        font-size: var(--ai-kit-label-size, 0.8125rem);
    }

    .ai-kit-card__preview {
        margin: 0;
        padding-inline-start: 1.125rem;
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
        font-size: 0.875rem;
    }

    .ai-kit-card__actions {
        display: flex;
        gap: 0.5rem;
        margin-block-start: 0.125rem;
    }

    .ai-kit-card__confirm,
    .ai-kit-card__reject {
        padding: 0.375rem 0.875rem;
        border-radius: var(--ai-kit-radius, 0.5rem);
        font: inherit;
        font-weight: 500;
        cursor: pointer;
    }

    .ai-kit-card__confirm {
        border: 1px solid transparent;
        background: var(--ai-kit-accent, #3b82f6);
        color: var(--ai-kit-accent-fg, #fff);
    }

    .ai-kit-card.is-destructive .ai-kit-card__confirm {
        background: var(--ai-kit-destructive, #ef4444);
        color: var(--ai-kit-destructive-fg, #fff);
    }

    .ai-kit-card__reject {
        border: 1px solid var(--ai-kit-border, color-mix(in oklab, currentColor 22%, transparent));
        background: transparent;
        color: inherit;
    }

    .ai-kit-card__confirm:disabled,
    .ai-kit-card__reject:disabled {
        cursor: default;
        opacity: 0.5;
    }

    .ai-kit-card__confirm:focus-visible,
    .ai-kit-card__reject:focus-visible {
        outline: 2px solid var(--ai-kit-accent, #3b82f6);
        outline-offset: 2px;
    }
</style>

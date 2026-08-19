/**
 * Presentation helpers for the approval form schema. The Vue and Svelte field
 * components are mirrors of each other, so every judgement they both have to
 * make — is this label a machine name, does this value need LTR isolation,
 * which widgets are long-form — lives here once instead of drifting between
 * two templates.
 *
 * The types themselves are part of the wire contract and stay in `./events`.
 */

import type { ApprovalCardField, ApprovalPayload, ClientDecision } from './events'

/** Widgets that render as a multi-line editor rather than an input. */
const LONG_TEXT: ReadonlySet<ApprovalCardField['widget']> = new Set(['textarea', 'markdown', 'code'])

/** Widgets whose content is source-shaped and reads better in a mono font. */
const MONO: ReadonlySet<ApprovalCardField['widget']> = new Set(['code', 'markdown'])

export const isLongText = (widget: ApprovalCardField['widget']): boolean => LONG_TEXT.has(widget)

export const isMono = (widget: ApprovalCardField['widget']): boolean => MONO.has(widget)

/**
 * What to print for a field's label, and whether that text is a raw argument
 * name rather than human copy.
 *
 * A machine name has to be isolated and rendered LTR: dropping `action` into
 * an RTL line unisolated is what turned `action: create` into a scrambled
 * "create action:" on the prod card. A tool that supplied its own `label` is
 * assumed to have written it in the conversation language, so it renders as
 * ordinary `dir="auto"` prose.
 */
export function fieldLabel(field: ApprovalCardField): { text: string; machine: boolean } {
    return field.label === null || field.label === ''
        ? { text: field.name, machine: true }
        : { text: field.label, machine: false }
}

/**
 * Whether a value should render mono + LTR: an id, a token, an enum member, a
 * path, a URL — anything with no whitespace and nothing outside printable
 * ASCII. Human copy in any language (and any Arabic text at all) fails the
 * test and renders as `dir="auto"` prose.
 */
export function isMachineValue(value: unknown): boolean {
    if (typeof value === 'number' || typeof value === 'boolean') {
        return true
    }

    if (typeof value !== 'string' || value === '') {
        return false
    }

    // Printable ASCII with no spaces: `course_id`, `create`, `a1b2-c3`, a URL.
    return /^[!-~]+$/.test(value)
}

/** A readonly field's value as display text. Never an empty cell. */
export function displayValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

/**
 * The `arguments` of an `{action: 'edit'}` decision: every EDITABLE field's
 * current value, and nothing else.
 *
 * Readonly and hidden fields are left out rather than echoed back — the server
 * restores them from the pending call either way
 * (`ApprovalCards::guardEdits()`), so sending them only invites a client to
 * think it owns them.
 *
 * @param edits values keyed by field name; a field the user has not touched
 * falls back to the value the card arrived with.
 */
export function editableArguments(
    fields: readonly ApprovalCardField[],
    edits: Record<string, unknown> = {},
): Record<string, unknown> {
    return Object.fromEntries(
        fields
            .filter((field) => field.editable)
            .map((field) => [field.name, edits[field.name] ?? field.value ?? null]),
    )
}

/**
 * The decision to send when the user confirms a card: a bare approve when
 * there is nothing editable to send (a one-click destructive card), an edit
 * carrying the form's values otherwise.
 *
 * Always resuming an editable card as an edit is deliberate — the server
 * reconciles the arguments against the pending call either way, so an
 * unchanged form and a changed one travel the same audited path.
 */
export function decisionFor(
    card: Pick<ApprovalPayload, 'editable' | 'fields'>,
    edits: Record<string, unknown> = {},
): ClientDecision {
    const args = editableArguments(card.fields, edits)

    return !card.editable || Object.keys(args).length === 0
        ? { action: 'approve' }
        : { action: 'edit', arguments: args }
}

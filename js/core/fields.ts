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
 * A raw argument name as a human label: `track_id` → `Track`, `createdBy` →
 * `Created by`, `name` → `Name`.
 *
 * The last resort, not the plan. A tool that wants its arguments labelled
 * declares them — `Field::make('body', FieldWidget::Markdown, label: 'المحتوى')`
 * — and that label reaches the card in the conversation's language. This runs
 * only for the arguments nobody labelled, and it exists because the prod card
 * that provoked the redesign printed `name` / `track_id` / `chapter_id` as
 * headline labels to an Arabic reader (owner ruling #22). An English-derived
 * word is not a translation; it is merely no longer a snake_case token.
 *
 * A trailing `id` / `uuid` token is dropped when something is left to name:
 * the row addresses a record, and `Track` reads as that record where
 * `Track id` reads as a database column.
 */
export function humanizeFieldName(name: string): string {
    const words = name
        // camelCase / PascalCase boundaries first, so `trackId` splits too.
        .replace(/([a-z\d])([A-Z])/g, '$1 $2')
        .split(/[\s_\-.]+/)
        .filter((word) => word !== '')
        .map((word) => word.toLowerCase())

    const named = words.length > 1 && /^(id|ids|uuid|uuids)$/.test(words[words.length - 1]!)
        ? words.slice(0, -1)
        : words

    const text = named.join(' ')

    if (text === '') {
        return name
    }

    return text.charAt(0).toUpperCase() + text.slice(1)
}

/**
 * What to print for a field's label, and whether that text is a raw argument
 * name rather than human copy.
 *
 * A tool that supplied its own `label` is assumed to have written it in the
 * conversation language, so it renders as ordinary `dir="auto"` prose.
 * Everything else is humanized from the argument name
 * ({@link humanizeFieldName}) — still Latin text inside a possibly RTL card,
 * so it stays bidi-ISOLATED either way: dropping `action` into an RTL line
 * unisolated is what turned `action: create` into a scrambled "create action:"
 * on the prod card.
 *
 * @deprecated the `machine` flag: since v0.9.0 a label is always human copy,
 * so nothing renders monospace any more. It stays for one version because the
 * components used to switch their font on it.
 */
export function fieldLabel(field: ApprovalCardField): { text: string; machine: boolean } {
    return field.label === null || field.label === ''
        ? { text: humanizeFieldName(field.name), machine: false }
        : { text: field.label, machine: false }
}

/**
 * Whether this field is the record's identity rather than something the user
 * is being asked about: `id`, `*_id`, `*_uuid` — and only a `readonly` one.
 * An editable id is a field the tool deliberately opened, and one rendered as
 * any other widget is a control the user is meant to reach; neither belongs in
 * a disclosure. (`ApprovalCards::guardEdits()` is what polices an edit either
 * way — this is presentation only.)
 *
 * Identity fields are the rows the prod approval card wasted its headline
 * space on — `track_id: v6oPvGqX` above the one argument that mattered. They
 * belong in a details disclosure, present for anyone who wants to audit the
 * write and out of the way of the decision.
 *
 * Because the test requires `readonly`, everything {@link partitionFields}
 * puts in `details` renders as a definition row — which is what lets both
 * component sets render that half identically.
 */
export function isIdentityField(field: ApprovalCardField): boolean {
    return (
        field.widget === 'readonly' &&
        !field.editable &&
        /(^|_)(id|uuid)s?$|([a-z\d])(Id|Uuid)s?$/.test(field.name)
    )
}

/**
 * The visible fields, split into the rows a card leads with and the technical
 * rows it tucks behind a disclosure. Hidden fields appear in neither — they
 * travel with the call and are never shown.
 *
 * Order is preserved inside each half, so a tool's declared field order still
 * decides what the reader meets first.
 */
export function partitionFields(fields: readonly ApprovalCardField[]): {
    rows: ApprovalCardField[]
    details: ApprovalCardField[]
} {
    const visible = fields.filter((field) => field.widget !== 'hidden')

    return {
        rows: visible.filter((field) => !isIdentityField(field)),
        details: visible.filter((field) => isIdentityField(field)),
    }
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

/**
 * A readonly field's value as display text. Never an empty cell — and never a
 * bare dash either: an argument the model left out reads as a localized
 * placeholder, because "—" in an Arabic card tells the reader nothing about
 * whether the field is empty, unknown or broken (owner ruling #22).
 *
 * @param emptyLabel the components pass their own `emptyLabel` prop through,
 * so an app localizes it once.
 */
export function displayValue(value: unknown, emptyLabel = 'غير محدَّد'): string {
    if (value === null || value === undefined || value === '') {
        return emptyLabel
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

/**
 * Presentation helpers for a whole pause card, next to `./fields` for the form
 * schema inside it. Same reason both exist: the Vue and Svelte cards are
 * mirrors, so every judgement they both have to make lives here once instead
 * of drifting between two templates.
 *
 * Everything here answers a defect the prod screenshots of 2026-08-20 showed
 * (owner ruling #22), and none of it guesses at server data: a card's title,
 * preview and fields all arrive resolved, and these functions only decide what
 * to PRINT.
 */

import type { ApprovalPayload } from './events'
import { humanizeFieldName } from './fields'

/** Whitespace-insensitive, case-insensitive sameness — for dedupe only. */
const sameText = (a: string, b: string): boolean =>
    a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()

/**
 * A card's preview rows as display lines, with the TITLE removed from them.
 *
 * The duplicate is not hypothetical: a tool whose `preview()` returns the same
 * sentence as its `title()` — the common shape, since both describe the one
 * write — rendered that sentence twice on the prod card, once bold in the
 * header and once as body text. Preview lines exist to say what the title
 * cannot, so a line that only repeats it is dropped rather than shown.
 *
 * Both wire shapes are accepted: a list of rows renders in order, and a
 * `{key: value}` map renders as `Label: value` rows with the key humanized the
 * same way an unlabelled field is. Blank rows are dropped either way — an
 * empty line in a preview is noise the reader has to interpret.
 */
export function previewLines(card: Pick<ApprovalPayload, 'title' | 'preview'>): string[] {
    const raw = Array.isArray(card.preview)
        ? card.preview.map((line) => text(line))
        : Object.entries(card.preview ?? {}).map(([key, value]) =>
            text(value) === '' ? '' : `${humanizeFieldName(key)}: ${text(value)}`,
        )

    return raw
        .map((line) => line.trim())
        .filter((line) => line !== '' && !sameText(line, card.title))
}

const text = (value: unknown): string => {
    if (value === null || value === undefined) {
        return ''
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

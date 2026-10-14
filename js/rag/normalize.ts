/**
 * The TypeScript twin of `Saad\AiKit\Rag\Text\ArabicNormalizer` — the fleet's
 * ONE match-side folding contract for Arabic + English search. Both sides are
 * tested against the same fixture (`tests/Fixtures/rag/normalize.json`), which
 * was generated from catodemy's server code, so what a client filter or
 * highlighter treats as a match is exactly what the server matched.
 *
 * Folding is MATCH-ONLY: never render a normalised string. `normalize()` keeps
 * case; `normalizeLower()` lowercases (the twin of SQL `lower()`).
 *
 * Mirroring notes (each one is a real PHP/PCRE behaviour reproduced here):
 *  - PCRE's `\s` under `/u` is the Unicode space set (Z + TAB..CR + NEL), not
 *    JavaScript's `\s` (which adds U+FEFF and drops NEL) → {@link WS}.
 *  - PCRE's `$` (no `m`, no `D`) also matches before a FINAL newline.
 *  - PHP `trim()` strips only " \t\n\r\0\x0B".
 */

export const MARKS = 'ًٌٍَُِّْـٰ'
export const FOLD_FROM = 'أإآةىؤئ'
export const FOLD_TO = 'اااهيءء'
export const DIGITS_FROM = '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹'
export const DIGITS_TO = '01234567890123456789'
export const TRANSLATE_FROM = FOLD_FROM + DIGITS_FROM + MARKS
export const TRANSLATE_TO = FOLD_TO + DIGITS_TO

/** PCRE `\s` under `/u` (UCP): the Z categories plus TAB, LF, VT, FF, CR and NEL. */
const WS = '\\t\\n\\v\\f\\r\\u0085\\p{Z}'

const PRESENTATION_FORMS = /[ﭐ-﷿ﹰ-ﻼ]/gu
const INVISIBLE_CONTROLS = /[​-‏‪-‮⁦-⁩﻿]/gu
const LETTER = '\\u0621-\\u063A\\u0641-\\u064A\\u0671-\\u06D3\\u06FA-\\u06FF'
const SHATTERED = new RegExp(
    `(?<![^${WS}])(?:[${LETTER}]{1,2}(?:[ \\t]+|(?=\\n?$))){3,}`,
    'gu',
)
const TOKEN_STRIP = /[&|!():*'"`\\<>]+/gu
const WHITESPACE_RUN = new RegExp(`[${WS}]+`, 'u')

const MARK_SET = new Set(MARKS)
const FOLD_MAP = new Map([...FOLD_FROM].map((ch, i) => [ch, [...FOLD_TO][i]]))

/** Full match-side normalisation; case is kept. */
export function normalize(text: string): string {
    return normalizeDigits(foldOrthography(stripMarks(canonicalize(text))))
}

/** {@link normalize} plus lowercasing — the twin of `translate(lower(col), …)`. */
export function normalizeLower(text: string): string {
    return normalize(text).toLowerCase()
}

/** Normalised, metacharacter-free query terms (case kept). */
export function tokens(text: string): string[] {
    const cleaned = phpTrim(normalize(text).replace(TOKEN_STRIP, ' '))

    return cleaned.split(WHITESPACE_RUN).filter((token) => token !== '')
}

/** Delete tashkeel, tatweel and superscript alef. */
export function stripMarks(text: string): string {
    let out = ''
    for (const ch of text) if (!MARK_SET.has(ch)) out += ch
    return out
}

/** Fold orthography variants, 1:1 in characters. */
export function foldOrthography(text: string): string {
    let out = ''
    for (const ch of text) out += FOLD_MAP.get(ch) ?? ch
    return out
}

/** Fold Arabic-Indic (U+0660–0669) and Persian (U+06F0–06F9) digits to ASCII. */
export function normalizeDigits(text: string): string {
    let out = ''
    for (const ch of text) {
        const code = ch.codePointAt(0) ?? 0
        if (code >= 0x0660 && code <= 0x0669) out += String(code - 0x0660)
        else if (code >= 0x06f0 && code <= 0x06f9) out += String(code - 0x06f0)
        else out += ch
    }
    return out
}

/** Presentation forms → letters, controls dropped, shattered words rejoined, NFC. */
export function canonicalize(text: string): string {
    if (text === '') return ''

    return joinShatteredWords(stripInvisibleControls(foldPresentationForms(text))).normalize('NFC')
}

/** Each presentation form through its own NFKC decomposition (never the whole string). */
export function foldPresentationForms(text: string): string {
    return text.replace(PRESENTATION_FORMS, (ch) => {
        const folded = ch.normalize('NFKC')
        return folded.startsWith(' ') ? folded.slice(1) : folded
    })
}

/** Remove zero-width and bidi control characters. */
export function stripInvisibleControls(text: string): string {
    return text.replace(INVISIBLE_CONTROLS, '')
}

/** Rejoin a word a PDF extractor emitted letter by letter («م ث ال»). */
export function joinShatteredWords(text: string): string {
    return text.replace(SHATTERED, (run) => {
        const parts = run.trim().split(/[ \t]+/u).filter((part) => part !== '')
        const singles = parts.filter((part) => [...part].length === 1).length

        if (singles < 2) return run

        const trailing = /[ \t]+$/u.exec(run)?.[0] ?? ''

        return parts.join('') + trailing
    })
}

/** PHP's default `trim()` character set. */
function phpTrim(text: string): string {
    return text.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')
}

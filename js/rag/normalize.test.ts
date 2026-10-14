import { describe, expect, it } from 'vitest'
import {
    DIGITS_FROM,
    DIGITS_TO,
    FOLD_FROM,
    FOLD_TO,
    MARKS,
    TRANSLATE_FROM,
    TRANSLATE_TO,
    canonicalize,
    normalize,
    normalizeLower,
    tokens,
} from './normalize'
import fixtureJson from '../../tests/Fixtures/rag/normalize.json'

type Case = {
    note: string
    input: string
    canonicalize: string
    normalize: string
    normalize_lower: string
    tokens: string[]
}

// The SAME fixture the PHP suite asserts (tests/Unit/Rag/ArabicNormalizerTest.php),
// generated from catodemy's server-side SearchQueryText — parity by construction.
const fixture = fixtureJson as { constants: Record<string, string>; cases: Case[] }

describe('rag/normalize — parity with the PHP contract', () => {
    it('spells the fold constants byte-for-byte', () => {
        expect(MARKS).toBe(fixture.constants.marks)
        expect(FOLD_FROM).toBe(fixture.constants.fold_from)
        expect(FOLD_TO).toBe(fixture.constants.fold_to)
        expect(DIGITS_FROM).toBe(fixture.constants.digits_from)
        expect(DIGITS_TO).toBe(fixture.constants.digits_to)
        expect(TRANSLATE_FROM).toBe(fixture.constants.translate_from)
        expect(TRANSLATE_TO).toBe(fixture.constants.translate_to)
    })

    it.each(fixture.cases.map((c) => [c.note, c] as const))('%s', (_note, c) => {
        expect(canonicalize(c.input)).toBe(c.canonicalize)
        expect(normalize(c.input)).toBe(c.normalize)
        expect(normalizeLower(c.input)).toBe(c.normalize_lower)
        expect(tokens(c.input)).toEqual(c.tokens)
    })

    it('is idempotent', () => {
        for (const c of fixture.cases) {
            expect(normalize(normalize(c.input))).toBe(normalize(c.input))
            expect(canonicalize(canonicalize(c.input))).toBe(canonicalize(c.input))
        }
    })
})

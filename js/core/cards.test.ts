import { describe, expect, it } from 'vitest'
import { previewLines } from './cards'

const card = (title: string, preview: unknown[] | Record<string, unknown>) => ({ title, preview })

describe('a card’s preview lines', () => {
    it('drops a line that only repeats the title', () => {
        // The prod defect: the tool returns the same sentence from title() and
        // preview(), and the card rendered it twice.
        expect(
            previewLines(
                card('Create chapter "Web" in "العابدية والزاهر 48"', [
                    'Create chapter "Web" in "العابدية والزاهر 48"',
                    'track: العابدية والزاهر 48',
                ]),
            ),
        ).toEqual(['track: العابدية والزاهر 48'])
    })

    it('ignores whitespace and case when comparing against the title', () => {
        expect(previewLines(card('Create chapter', ['  create   CHAPTER '])).length).toBe(0)
    })

    it('keeps a line that says more than the title', () => {
        expect(previewLines(card('Create chapter', ['Create chapter in track 48']))).toEqual([
            'Create chapter in track 48',
        ])
    })

    it('drops blank rows rather than rendering an empty bullet', () => {
        expect(previewLines(card('t', ['  ', '', null, 'kept']))).toEqual(['kept'])
    })

    it('renders a map preview as humanized label rows', () => {
        expect(previewLines(card('t', { track_id: 'v6oPvGqX', name: 'Web', note: null }))).toEqual([
            'Track: v6oPvGqX',
            'Name: Web',
        ])
    })

    it('stringifies a nested row rather than printing [object Object]', () => {
        expect(previewLines(card('t', [{ a: 1 }]))).toEqual(['{"a":1}'])
    })

    it('handles a tool that renders no preview at all', () => {
        expect(previewLines(card('t', []))).toEqual([])
        expect(previewLines(card('t', {}))).toEqual([])
    })
})

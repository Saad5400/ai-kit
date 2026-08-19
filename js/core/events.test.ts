import { describe, expect, it } from 'vitest'
import { closesReasoning, editableArguments, isAiKitEvent, isTerminal } from './events'
import type { ApprovalCardField } from './events'

const field = (
    name: string,
    widget: ApprovalCardField['widget'],
    editable: boolean,
    value: unknown,
): ApprovalCardField => ({ name, widget, editable, label: null, options: null, placeholder: null, value })

describe('the wire contract helpers', () => {
    it('tells a contract event from an app extension event', () => {
        expect(isAiKitEvent('delta')).toBe(true)
        expect(isAiKitEvent('tool')).toBe(true)
        expect(isAiKitEvent('approval')).toBe(true)
        expect(isAiKitEvent('step')).toBe(false)
        expect(isAiKitEvent('message')).toBe(false)
    })

    it('treats done and error as the only terminal events', () => {
        expect(isTerminal('done')).toBe(true)
        expect(isTerminal('error')).toBe(true)
        expect(isTerminal('delta')).toBe(false)
        expect(isTerminal('approval')).toBe(false)
    })

    it('closes an open thinking block on text, tools and the terminals', () => {
        expect(closesReasoning('delta')).toBe(true)
        expect(closesReasoning('tool')).toBe(true)
        expect(closesReasoning('done')).toBe(true)
        expect(closesReasoning('error')).toBe(true)
        // Reasoning does not close itself — a block stays open across deltas.
        expect(closesReasoning('reasoning')).toBe(false)
    })
})

describe('the approval form schema', () => {
    const fields: ApprovalCardField[] = [
        field('article_id', 'readonly', false, 5),
        field('internal_note', 'hidden', false, 'server-only'),
        field('body', 'markdown', true, 'original'),
        field('summary', 'textarea', true, null),
    ]

    it('sends only editable fields as the edit arguments', () => {
        expect(editableArguments(fields, { body: 'edited' })).toEqual({
            body: 'edited',
            summary: null,
        })
    })

    it('falls back to the value the card arrived with for untouched fields', () => {
        expect(editableArguments(fields)).toEqual({ body: 'original', summary: null })
    })

    it('drops a readonly or hidden edit rather than echoing it back', () => {
        expect(editableArguments(fields, { article_id: 999, internal_note: 'x' })).toEqual({
            body: 'original',
            summary: null,
        })
    })
})

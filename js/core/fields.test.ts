import { describe, expect, it } from 'vitest'
import type { ApprovalCardField } from './events'
import {
    decisionFor,
    displayValue,
    editableArguments,
    fieldLabel,
    isLongText,
    isMachineValue,
    isMono,
} from './fields'

const field = (
    name: string,
    widget: ApprovalCardField['widget'],
    editable: boolean,
    value: unknown,
    label: string | null = null,
): ApprovalCardField => ({ name, widget, editable, label, options: null, placeholder: null, value })

const fields: ApprovalCardField[] = [
    field('article_id', 'readonly', false, 5),
    field('internal_note', 'hidden', false, 'server-only'),
    field('body', 'markdown', true, 'original'),
    field('summary', 'textarea', true, null),
]

describe('building the edit arguments', () => {
    it('sends only editable fields', () => {
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

describe('the decision a confirmed card sends', () => {
    it('sends an edit carrying the form values when the card is editable', () => {
        expect(decisionFor({ editable: true, fields }, { body: 'edited' })).toEqual({
            action: 'edit',
            arguments: { body: 'edited', summary: null },
        })
    })

    it('sends a bare approve for a one-click card', () => {
        expect(decisionFor({ editable: false, fields })).toEqual({ action: 'approve' })
    })

    it('sends a bare approve when an editable card has no editable field', () => {
        expect(
            decisionFor({ editable: true, fields: [field('id', 'readonly', false, 1)] }),
        ).toEqual({ action: 'approve' })
    })
})

describe('deciding how a field reads', () => {
    it('treats a bare argument name as a machine name and a tool label as prose', () => {
        expect(fieldLabel(field('course_id', 'text', true, 1))).toEqual({
            text: 'course_id',
            machine: true,
        })
        expect(fieldLabel(field('body', 'text', true, '', 'المحتوى'))).toEqual({
            text: 'المحتوى',
            machine: false,
        })
        // An empty label is not a label.
        expect(fieldLabel(field('body', 'text', true, '', '')).machine).toBe(true)
    })

    it('isolates ids, enum members and tokens but not human copy', () => {
        expect(isMachineValue('create')).toBe(true)
        expect(isMachineValue('course-widget_id')).toBe(true)
        expect(isMachineValue('https://example.test/a?b=1')).toBe(true)
        expect(isMachineValue(42)).toBe(true)
        expect(isMachineValue(true)).toBe(true)
        // Anything with a space, and any Arabic at all, is prose.
        expect(isMachineValue('create widget')).toBe(false)
        expect(isMachineValue('إنشاء')).toBe(false)
        expect(isMachineValue('')).toBe(false)
        expect(isMachineValue(null)).toBe(false)
    })

    it('never renders an empty readonly cell', () => {
        expect(displayValue(null)).toBe('—')
        expect(displayValue('')).toBe('—')
        expect(displayValue(0)).toBe('0')
        expect(displayValue(false)).toBe('false')
        expect(displayValue({ a: 1 })).toBe('{"a":1}')
    })

    it('knows which widgets are long-form and which are mono', () => {
        expect(isLongText('textarea')).toBe(true)
        expect(isLongText('markdown')).toBe(true)
        expect(isLongText('code')).toBe(true)
        expect(isLongText('text')).toBe(false)
        expect(isMono('code')).toBe(true)
        expect(isMono('markdown')).toBe(true)
        expect(isMono('textarea')).toBe(false)
    })
})

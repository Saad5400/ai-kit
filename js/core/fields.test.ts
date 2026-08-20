import { describe, expect, it } from 'vitest'
import type { ApprovalCardField } from './events'
import {
    decisionFor,
    displayValue,
    editableArguments,
    fieldLabel,
    humanizeFieldName,
    isIdentityField,
    isLongText,
    isMachineValue,
    isMono,
    partitionFields,
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
    it('humanizes a bare argument name and takes a tool label verbatim', () => {
        expect(fieldLabel(field('course_id', 'text', true, 1))).toEqual({
            text: 'Course',
            machine: false,
        })
        expect(fieldLabel(field('body', 'text', true, '', 'المحتوى'))).toEqual({
            text: 'المحتوى',
            machine: false,
        })
        // An empty label is not a label.
        expect(fieldLabel(field('body', 'text', true, '', '')).text).toBe('Body')
    })

    it('humanizes snake, kebab and camel names, dropping a trailing id', () => {
        expect(humanizeFieldName('name')).toBe('Name')
        expect(humanizeFieldName('track_id')).toBe('Track')
        expect(humanizeFieldName('chapter-uuid')).toBe('Chapter')
        expect(humanizeFieldName('createdBy')).toBe('Created by')
        expect(humanizeFieldName('publish_at')).toBe('Publish at')
        // Nothing would be left once the suffix goes, so the suffix stays.
        expect(humanizeFieldName('id')).toBe('Id')
        // Never returns an empty label.
        expect(humanizeFieldName('__')).toBe('__')
    })

    it('treats a readonly id as identity and anything reachable as a row', () => {
        expect(isIdentityField(field('id', 'readonly', false, 1))).toBe(true)
        expect(isIdentityField(field('track_id', 'readonly', false, 'v6oPvGqX'))).toBe(true)
        expect(isIdentityField(field('trackId', 'readonly', false, 'v6oPvGqX'))).toBe(true)
        // Editable, so the tool opened it on purpose.
        expect(isIdentityField(field('track_id', 'text', true, 'v6oPvGqX'))).toBe(false)
        // Readonly but not an id: a resolved record the reader wants to see.
        expect(isIdentityField(field('course', 'readonly', false, 'مقرر'))).toBe(false)
        // A widget the user can reach is never hidden behind a disclosure.
        expect(isIdentityField(field('track_id', 'select', false, 'v6oPvGqX'))).toBe(false)
    })

    it('splits the visible fields into headline rows and technical details', () => {
        const split = partitionFields([
            field('chapter_id', 'readonly', false, null),
            field('name', 'text', true, 'Web'),
            field('internal_note', 'hidden', false, 'server-only'),
            field('track_id', 'readonly', false, 'v6oPvGqX'),
            field('course', 'readonly', false, 'مقرر'),
        ])

        // Declared order survives inside each half; hidden appears in neither.
        expect(split.rows.map((entry) => entry.name)).toEqual(['name', 'course'])
        expect(split.details.map((entry) => entry.name)).toEqual(['chapter_id', 'track_id'])
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
        // A localized placeholder, never the bare dash prod showed.
        expect(displayValue(null)).toBe('غير محدَّد')
        expect(displayValue('')).toBe('غير محدَّد')
        expect(displayValue(null, 'Not set')).toBe('Not set')
        expect(displayValue(undefined, 'Not set')).toBe('Not set')
        // A real value is never replaced by the placeholder.
        expect(displayValue(0, 'Not set')).toBe('0')
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

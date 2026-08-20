import { describe, expect, it } from 'vitest'
import type { ApprovalPayload, QuestionPayload } from './events'
import { createTimeline, groupSegments } from './timeline'
import type { Segment, ToolSegment } from './timeline'

const approval = (id: string): ApprovalPayload => ({
    kind: 'approval',
    id,
    tool: 'DeleteWidget',
    title: 'Delete widget',
    destructive: true,
    undoable: false,
    editable: false,
    arguments: { id: 4 },
    fields: [
        { name: 'id', widget: 'readonly', editable: false, label: null, options: null, placeholder: null, value: 4 },
    ],
    preview: [],
    reason: 'Permanently deletes the widget.',
})

const question = (id: string): QuestionPayload => ({
    kind: 'question',
    id,
    question: 'Which semester?',
    options: ['Fall', 'Spring'],
})

/** A whole turn, in the order the wire delivers it. */
const play = (events: Array<[string, unknown]>): Segment[] => {
    const timeline = createTimeline()

    for (const [event, data] of events) {
        timeline.push(event, data)
    }

    return timeline.segments
}

describe('the segment timeline', () => {
    it('keeps interleaved channels in arrival order', () => {
        const segments = play([
            ['delta', { text: 'Let me check. ' }],
            ['reasoning', { text: 'The user wants ' }],
            ['reasoning', { text: 'the fall term.' }],
            ['tool', { id: 'c1', name: 'Lookup', status: 'running' }],
            ['tool', { id: 'c1', name: 'Lookup', status: 'done', successful: true }],
            ['delta', { text: 'Found it.' }],
            ['reasoning', { text: 'Thinking again.' }],
            ['delta', { text: ' Here you go.' }],
        ])

        expect(segments).toEqual([
            { type: 'text', text: 'Let me check. ' },
            { type: 'thinking', text: 'The user wants the fall term.' },
            { type: 'tool', id: 'c1', name: 'Lookup', status: 'done', successful: true },
            { type: 'text', text: 'Found it.' },
            { type: 'thinking', text: 'Thinking again.' },
            { type: 'text', text: ' Here you go.' },
        ])
    })

    it('merges consecutive deltas of the same kind and no others', () => {
        const segments = play([
            ['delta', { text: 'a' }],
            ['delta', { text: 'b' }],
            ['reasoning', { text: 'x' }],
            ['reasoning', { text: 'y' }],
            ['delta', { text: 'c' }],
        ])

        expect(segments).toEqual([
            { type: 'text', text: 'ab' },
            { type: 'thinking', text: 'xy' },
            { type: 'text', text: 'c' },
        ])
    })

    it('ignores empty text rather than opening a blank segment', () => {
        expect(play([['delta', { text: '' }]])).toEqual([])
        expect(play([['reasoning', {}]])).toEqual([])
    })

    it('updates a running tool in place, so the chip does not jump to the end', () => {
        const segments = play([
            ['tool', { id: 'c1', name: 'Search', status: 'running' }],
            ['delta', { text: 'meanwhile…' }],
            ['tool', { id: 'c2', name: 'Fetch', status: 'running' }],
            ['tool', { id: 'c1', name: 'Search', status: 'done', successful: false }],
        ])

        expect(segments).toEqual([
            { type: 'tool', id: 'c1', name: 'Search', status: 'done', successful: false },
            { type: 'text', text: 'meanwhile…' },
            { type: 'tool', id: 'c2', name: 'Fetch', status: 'running' },
        ])
    })

    it('keeps the held name when a progress frame omits it, and replaces progress wholesale', () => {
        const timeline = createTimeline()

        timeline.push('tool', { id: 'c1', name: 'GradeAll', status: 'running' })
        timeline.push('tool', { id: 'c1', status: 'running', progress: { label: 'Grading', current: 3, total: 40 } })

        expect(timeline.segments).toEqual([
            {
                type: 'tool',
                id: 'c1',
                name: 'GradeAll',
                status: 'running',
                progress: { label: 'Grading', current: 3, total: 40 },
            },
        ])

        // Wholesale: the next report's missing `total` is gone, not merged in.
        timeline.push('tool', { id: 'c1', status: 'running', progress: { percent: 60 } })

        expect((timeline.segments[0] as ToolSegment).progress).toEqual({ percent: 60 })
    })

    it('keeps the held progress when a running frame carries none', () => {
        const timeline = createTimeline()

        timeline.push('tool', { id: 'c1', name: 'GradeAll', status: 'running', progress: { current: 3, total: 40 } })
        timeline.push('tool', { id: 'c1', status: 'running' })

        expect((timeline.segments[0] as ToolSegment).progress).toEqual({ current: 3, total: 40 })
    })

    it('drops progress on done — a settled chip must not keep a bar', () => {
        const timeline = createTimeline()

        timeline.push('tool', { id: 'c1', name: 'GradeAll', status: 'running' })
        timeline.push('tool', { id: 'c1', status: 'running', progress: { current: 39, total: 40 } })
        timeline.push('tool', { id: 'c1', status: 'done', successful: true })

        expect(timeline.segments).toEqual([
            { type: 'tool', id: 'c1', name: 'GradeAll', status: 'done', successful: true },
        ])
        expect('progress' in timeline.segments[0]!).toBe(false)
    })

    it('folds a card over a chip that was reporting progress — the fold rule holds', () => {
        const segments = play([
            ['tool', { id: 'c1', name: 'DeleteWidget', status: 'running' }],
            ['tool', { id: 'c1', status: 'running', progress: { label: 'Checking references' } }],
            ['approval', approval('c1')],
        ])

        expect(segments).toEqual([{ type: 'card', card: approval('c1') }])
    })

    it('folds an approval card into the same-id tool chip, in place', () => {
        const segments = play([
            ['delta', { text: 'One moment.' }],
            ['tool', { id: 'c1', name: 'DeleteWidget', status: 'running' }],
            ['approval', approval('c1')],
        ])

        expect(segments).toEqual([
            { type: 'text', text: 'One moment.' },
            { type: 'card', card: approval('c1') },
        ])
    })

    it('folds a question card the same way, and repaints a card it already holds', () => {
        const timeline = createTimeline()

        timeline.push('tool', { id: 'q1', name: 'AskUser', status: 'running' })
        timeline.push('question', question('q1'))
        timeline.push('question', { ...question('q1'), question: 'Which term?' })

        expect(timeline.segments).toHaveLength(1)
        expect((timeline.segments[0] as { card: QuestionPayload }).card.question).toBe('Which term?')
    })

    it('appends a card with no matching chip', () => {
        const segments = play([
            ['delta', { text: 'Done.' }],
            ['approval', approval('c9')],
        ])

        expect(segments.map((segment) => segment.type)).toEqual(['text', 'card'])
    })

    it('mutates the array it was handed, never replacing it', () => {
        const target: Segment[] = []
        const timeline = createTimeline(target)

        timeline.push('delta', { text: 'hi' })

        expect(timeline.segments).toBe(target)
        expect(target).toHaveLength(1)
    })

    it('ignores the events it does not model', () => {
        expect(
            play([
                ['citations', { items: [] }],
                ['step', { n: 1 }],
                ['done', { conversation_id: 'c' }],
                ['error', { message: 'boom' }],
            ]),
        ).toEqual([])
    })

    it('drops a malformed tool or card payload instead of pushing a broken segment', () => {
        expect(play([['tool', { name: 'NoId', status: 'running' }]])).toEqual([])
        expect(play([['approval', { kind: 'approval' }]])).toEqual([])
    })
})

describe('grouping segments for render', () => {
    it('collapses consecutive thinking and tools into one process group', () => {
        const groups = groupSegments(
            play([
                ['delta', { text: 'Checking.' }],
                ['reasoning', { text: 'hmm' }],
                ['tool', { id: 'c1', name: 'A', status: 'done', successful: true }],
                ['reasoning', { text: 'more' }],
                ['tool', { id: 'c2', name: 'B', status: 'done', successful: true }],
                ['delta', { text: 'Answer.' }],
            ]),
        )

        expect(groups.map((group) => group.type)).toEqual(['text', 'process', 'text'])
        expect(groups[1]).toMatchObject({
            items: [
                { type: 'thinking', text: 'hmm' },
                { type: 'tool', id: 'c1' },
                { type: 'thinking', text: 'more' },
                { type: 'tool', id: 'c2' },
            ],
        })
    })

    it('keeps text segments separate — order is the whole point', () => {
        const groups = groupSegments(
            play([
                ['delta', { text: 'first' }],
                ['reasoning', { text: 'thinking' }],
                ['delta', { text: 'second' }],
            ]),
        )

        expect(groups).toEqual([
            { type: 'text', text: 'first' },
            { type: 'process', items: [{ type: 'thinking', text: 'thinking' }] },
            { type: 'text', text: 'second' },
        ])
    })

    it('never swallows a card into a process group — a decision surface stays top-level', () => {
        const groups = groupSegments(
            play([
                ['reasoning', { text: 'careful now' }],
                ['tool', { id: 'c1', name: 'DeleteWidget', status: 'running' }],
                ['approval', approval('c2')],
                ['reasoning', { text: 'and on' }],
            ]),
        )

        expect(groups.map((group) => group.type)).toEqual(['process', 'card', 'process'])
        expect((groups[0] as { items: unknown[] }).items).toHaveLength(2)
    })

    it('breaks a pending card out of the thinking that surrounds it', () => {
        // Owner ruling #22: a card the user must act on cannot end up inside a
        // collapsed disclosure. Thinking on both sides of it, and a chip whose
        // own id the card did NOT fold, is the worst case for that guarantee.
        const groups = groupSegments(
            play([
                ['reasoning', { text: 'first' }],
                ['tool', { id: 't1', name: 'Lookup', status: 'done', successful: true }],
                ['question', question('q1')],
                ['tool', { id: 't2', name: 'Lookup', status: 'running' }],
                ['reasoning', { text: 'second' }],
                ['approval', approval('a1')],
                ['reasoning', { text: 'third' }],
            ]),
        )

        expect(groups.map((group) => group.type)).toEqual([
            'process',
            'card',
            'process',
            'card',
            'process',
        ])

        // Neither card is reachable from inside a process group's items.
        const nested = groups
            .flatMap((group) => (group.type === 'process' ? group.items : []))
            .filter((item) => item.type !== 'thinking' && item.type !== 'tool')

        expect(nested).toEqual([])
    })

    it('keeps a card top-level whatever its kind or state', () => {
        const groups = groupSegments(
            play([
                ['question', question('q1')],
                ['approval', approval('a1')],
            ]),
        )

        expect(groups.map((group) => group.type)).toEqual(['card', 'card'])
    })

    it('groups nothing out of an empty turn', () => {
        expect(groupSegments([])).toEqual([])
    })
})

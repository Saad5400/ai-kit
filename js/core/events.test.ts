import { describe, expect, it } from 'vitest'
import { closesReasoning, isAiKitEvent, isTerminal } from './events'

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

import { describe, expect, it } from 'vitest'
import { closesReasoning, isAiKitEvent, isTerminal, type ErrorEvent, type ErrorPayload } from './events'

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

describe('the error payload', () => {
    it('keeps code optional, so a code-less frame from an older server still types', () => {
        const legacy: ErrorPayload = JSON.parse('{"message":"حدث خطأ"}')
        const coded: ErrorEvent = { event: 'error', data: { message: 'down', code: 'provider_unavailable' } }
        // A code a later kit adds is still a string, not a type error.
        const future: ErrorPayload = { message: 'x', code: 'some_new_code' }

        expect(legacy.code).toBeUndefined()
        expect(coded.data.code).toBe('provider_unavailable')
        expect(future.code).toBe('some_new_code')
    })
})

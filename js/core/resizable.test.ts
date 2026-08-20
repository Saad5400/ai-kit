import { afterEach, describe, expect, it } from 'vitest'
import { createResizable, readDirection } from './resizable'

/**
 * A MediaQueryList stand-in whose `matches` the test owns, so the desktop
 * breakpoint can be crossed in place — jsdom's own `matchMedia` never matches
 * anything and cannot be changed.
 */
const fakeMedia = (matches: boolean) => {
    const listeners = new Set<() => void>()

    const query = {
        matches,
        addEventListener: (_: string, listener: () => void) => listeners.add(listener),
        removeEventListener: (_: string, listener: () => void) => listeners.delete(listener),
        listeners,
        set(next: boolean) {
            query.matches = next
            listeners.forEach((listener) => listener())
        },
    }

    globalThis.matchMedia = ((): MediaQueryList => query as unknown as MediaQueryList) as typeof matchMedia

    return query
}

const fakeStorage = (seed: Record<string, string> = {}) => {
    const map = new Map(Object.entries(seed))

    return {
        getItem: (key: string) => map.get(key) ?? null,
        setItem: (key: string, value: string) => void map.set(key, value),
        removeItem: (key: string) => void map.delete(key),
        map,
    }
}

const mount = () => {
    const panel = document.createElement('aside')
    const handle = document.createElement('div')

    panel.append(handle)
    document.body.append(panel)

    return { panel, handle }
}

/** jsdom has no PointerEvent; the helper only reads MouseEvent fields. */
const pointer = (type: string, clientX = 0): MouseEvent =>
    new MouseEvent(type, { clientX, bubbles: true })

const drag = (handle: HTMLElement, from: number, to: number): void => {
    handle.dispatchEvent(pointer('pointerdown', from))
    document.dispatchEvent(pointer('pointermove', to))
    document.dispatchEvent(pointer('pointerup', to))
}

afterEach(() => {
    document.body.innerHTML = ''
    document.documentElement.removeAttribute('dir')
})

describe('resizing a docked panel', () => {
    it('applies the stored width on a desktop viewport', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        expect(resizable.width()).toBe(400)
        expect(panel.style.width).toBe('400px')
    })

    it('grows the panel when the handle is dragged toward the inline start', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        // LTR: the inline start is to the LEFT, so a leftward drag widens.
        drag(handle, 500, 450)

        expect(resizable.width()).toBe(450)
    })

    it('flips the delta in RTL, where the inline start is to the right', () => {
        fakeMedia(true)
        document.documentElement.setAttribute('dir', 'rtl')
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        // The SAME leftward drag now narrows the panel.
        drag(handle, 500, 450)

        expect(resizable.width()).toBe(350)
    })

    it('flips again for a panel docked at the inline start', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            dock: 'inline-start',
            storage: fakeStorage({ w: '400' }),
        })

        drag(handle, 500, 450)

        expect(resizable.width()).toBe(350)
    })

    it('clamps to the min and max bounds', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            min: 300,
            max: 500,
            storage: fakeStorage({ w: '400' }),
        })

        drag(handle, 500, 1_000)
        expect(resizable.width()).toBe(300)

        drag(handle, 500, -1_000)
        expect(resizable.width()).toBe(500)
    })

    it('clamps a stored width that no longer fits the bounds', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            max: 500,
            storage: fakeStorage({ w: '9000' }),
        })

        expect(resizable.width()).toBe(500)
    })

    it('persists on release rather than on every frame', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const storage = fakeStorage({ w: '400' })

        createResizable({ handle, panel, storageKey: 'w', storage })

        handle.dispatchEvent(pointer('pointerdown', 500))
        document.dispatchEvent(pointer('pointermove', 450))

        expect(storage.map.get('w')).toBe('400')

        document.dispatchEvent(pointer('pointerup', 450))

        expect(storage.map.get('w')).toBe('450')
    })

    it('resizes from the keyboard in the same logical direction', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const storage = fakeStorage({ w: '400' })

        const resizable = createResizable({ handle, panel, storageKey: 'w', step: 20, storage })

        handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }))

        expect(resizable.width()).toBe(420)
        expect(storage.map.get('w')).toBe('420')

        handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))

        expect(resizable.width()).toBe(400)
    })

    it('marks the handle as a separator a caller can find and focus', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        createResizable({ handle, panel, storageKey: 'w', min: 300, max: 500, storage: fakeStorage({ w: '400' }) })

        expect(handle.getAttribute('role')).toBe('separator')
        expect(handle.getAttribute('aria-orientation')).toBe('vertical')
        expect(handle.getAttribute('tabindex')).toBe('0')
        expect(handle.getAttribute('aria-valuemin')).toBe('300')
        expect(handle.getAttribute('aria-valuemax')).toBe('500')
        expect(handle.getAttribute('aria-valuenow')).toBe('400')
    })

    it('flags the drag on the handle and the document while it lasts', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        createResizable({ handle, panel, storageKey: 'w', storage: fakeStorage({ w: '400' }) })

        handle.dispatchEvent(pointer('pointerdown', 500))

        expect(handle.classList.contains('is-dragging')).toBe(true)
        expect(document.documentElement.classList.contains('ai-kit-resizing')).toBe(true)

        document.dispatchEvent(pointer('pointerup', 500))

        expect(handle.classList.contains('is-dragging')).toBe(false)
        expect(document.documentElement.classList.contains('ai-kit-resizing')).toBe(false)
    })

    it('forgets the width on reset', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const storage = fakeStorage({ w: '400' })

        const resizable = createResizable({ handle, panel, storageKey: 'w', storage })

        resizable.reset()

        expect(resizable.width()).toBeNull()
        expect(panel.style.width).toBe('')
        expect(storage.map.has('w')).toBe(false)
    })

    it('takes a width programmatically, clamped and persisted', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const storage = fakeStorage()

        const resizable = createResizable({ handle, panel, storageKey: 'w', max: 500, storage })

        resizable.resize(9_000)

        expect(resizable.width()).toBe(500)
        expect(storage.map.get('w')).toBe('500')
    })

    it('reports every applied width through onresize', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const seen: Array<number | null> = []

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
            onresize: (width) => seen.push(width),
        })

        resizable.reset()

        expect(seen).toEqual([400, null])
    })

    it('applies a caller’s own apply() instead of an inline width', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
            apply: (width, element) =>
                element.style.setProperty('--assistant-width', width === null ? '' : `${width}px`),
        })

        expect(panel.style.width).toBe('')
        expect(panel.style.getPropertyValue('--assistant-width')).toBe('400px')
    })
})

describe('below the desktop breakpoint', () => {
    it('applies nothing and ignores a drag', () => {
        fakeMedia(false)
        const { panel, handle } = mount()
        const storage = fakeStorage({ w: '400' })

        const resizable = createResizable({ handle, panel, storageKey: 'w', storage })

        expect(resizable.width()).toBeNull()
        expect(panel.style.width).toBe('')

        drag(handle, 500, 450)

        // Neither the panel nor the remembered width moved.
        expect(resizable.width()).toBeNull()
        expect(storage.map.get('w')).toBe('400')
    })

    it('refuses a programmatic resize too', () => {
        fakeMedia(false)
        const { panel, handle } = mount()

        const resizable = createResizable({ handle, panel, storageKey: 'w', storage: fakeStorage() })

        resizable.resize(400)

        expect(resizable.width()).toBeNull()
    })

    it('turns on and off as the viewport crosses the query', () => {
        const query = fakeMedia(false)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        expect(resizable.width()).toBeNull()

        query.set(true)

        expect(resizable.width()).toBe(400)
        expect(panel.style.width).toBe('400px')

        query.set(false)

        expect(resizable.width()).toBeNull()
        expect(panel.style.width).toBe('')
    })
})

describe('tearing down', () => {
    it('drops every listener on destroy', () => {
        const query = fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        resizable.destroy()

        drag(handle, 500, 450)
        handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }))

        expect(resizable.width()).toBe(400)
        expect(query.listeners.size).toBe(0)
    })

    it('leaves the applied width in place, so teardown never jumps the layout', () => {
        fakeMedia(true)
        const { panel, handle } = mount()

        const resizable = createResizable({
            handle,
            panel,
            storageKey: 'w',
            storage: fakeStorage({ w: '400' }),
        })

        resizable.destroy()

        expect(panel.style.width).toBe('400px')
    })

    it('survives storage that throws on every access', () => {
        fakeMedia(true)
        const { panel, handle } = mount()
        const throwing = {
            getItem: () => {
                throw new Error('blocked')
            },
            setItem: () => {
                throw new Error('blocked')
            },
            removeItem: () => {
                throw new Error('blocked')
            },
        }

        const resizable = createResizable({ handle, panel, storageKey: 'w', storage: throwing })

        expect(resizable.width()).toBeNull()

        expect(() => resizable.resize(400)).not.toThrow()
        expect(resizable.width()).toBe(400)
    })
})

describe('reading the writing direction', () => {
    it('prefers the nearest declared dir over the computed style', () => {
        const { panel } = mount()

        expect(readDirection(panel)).toBe('ltr')

        document.documentElement.setAttribute('dir', 'rtl')
        expect(readDirection(panel)).toBe('rtl')

        // A panel that declares its own direction wins over the document's.
        panel.setAttribute('dir', 'ltr')
        expect(readDirection(panel)).toBe('ltr')
    })
})

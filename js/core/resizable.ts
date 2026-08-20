/**
 * Pointer-drag resizing for a docked panel — the assistant sidebar, in every
 * app that hosts one (owner ruling #23: the sidebar is user-resizable on
 * desktop, its width remembered per browser, and the kit ships the shared
 * helper rather than each app re-deriving it).
 *
 * Framework-free on purpose: the Svelte action (`js/svelte/resizable.ts`) and
 * the Vue composable (`js/vue/resizable.ts`) are thin wrappers over this, so
 * the direction handling and the persistence behave identically in both.
 *
 * RTL. The drag is expressed in LOGICAL terms — the handle sits on the panel's
 * inline-START edge when the panel is docked at the inline END (the usual
 * sidebar), and dragging it toward the inline start makes the panel wider. The
 * physical direction of "toward the inline start" flips with the document's
 * direction, so the delta's SIGN is read per drag from the panel's own
 * direction rather than baked in. A sidebar that grows in English and shrinks
 * in Arabic is the whole reason this lives in the kit.
 *
 * DESKTOP ONLY. Below the breakpoint (`media`, default `(min-width: 1024px)`)
 * the helper is inert: no listeners, and no stored width applied — a phone
 * layout that stacks the panel must not inherit a 520px width from someone's
 * laptop. It watches the query, so rotating a tablet across the breakpoint
 * turns resizing on and off without a remount.
 *
 *     const resizable = createResizable({
 *         handle: handleEl,
 *         panel: panelEl,
 *         storageKey: 'catodemy.assistant.width',
 *     })
 *
 *     // later
 *     resizable.destroy()
 */

export type ResizableOptions = {
    /** The element the user drags. Gets the separator role and key handling. */
    handle: HTMLElement
    /** The panel being resized. */
    panel: HTMLElement
    /**
     * `localStorage` key the width is remembered under. Namespace it per app
     * and per surface — two sidebars sharing a key fight over one number.
     */
    storageKey: string
    /** Narrowest allowed width, in px. */
    min?: number
    /** Widest allowed width, in px. */
    max?: number
    /** The desktop query. Below it the helper does nothing at all. */
    media?: string
    /**
     * Which edge of its container the panel is docked to. `inline-end` (the
     * default) is a sidebar after the content: right in LTR, left in RTL.
     */
    dock?: 'inline-start' | 'inline-end'
    /** Arrow-key increment, in px. */
    step?: number
    /**
     * Where the width is persisted. Pass `null` to keep the drag ephemeral;
     * defaults to `localStorage` when it is reachable (it throws outright in
     * some sandboxed iframes, which is caught here rather than by the caller).
     */
    storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null
    /**
     * How a width lands on the panel. The default sets an inline `width` in px
     * and clears it when the width is `null` (mobile, or after `reset()`).
     * Override it for a layout driven by a CSS variable or a grid track.
     */
    apply?: (width: number | null, panel: HTMLElement) => void
    /** Called after every applied change — including the mobile no-op. */
    onresize?: (width: number | null) => void
}

export type Resizable = {
    /** The width in force, or `null` when none is applied. */
    width(): number | null
    /** Set the width programmatically; clamped and persisted like a drag. */
    resize(width: number): void
    /** Forget the stored width and hand the panel back to the stylesheet. */
    reset(): void
    /** Detach every listener. The applied width is deliberately left alone. */
    destroy(): void
}

const DESKTOP = '(min-width: 1024px)'

export function createResizable(options: ResizableOptions): Resizable {
    const {
        handle,
        panel,
        storageKey,
        min = 280,
        max = 720,
        media = DESKTOP,
        dock = 'inline-end',
        step = 24,
        apply = defaultApply,
        onresize,
    } = options

    const storage = options.storage === undefined ? localStorageOrNull() : options.storage
    const clamp = (width: number): number => Math.round(Math.min(max, Math.max(min, width)))

    let width: number | null = null
    let start: { x: number; width: number; grow: 1 | -1 } | null = null

    const set = (next: number | null, persist: boolean): void => {
        width = next === null ? null : clamp(next)

        apply(width, panel)

        if (width !== null) {
            handle.setAttribute('aria-valuenow', String(width))
        } else {
            handle.removeAttribute('aria-valuenow')
        }

        if (persist && storage !== null) {
            try {
                width === null
                    ? storage.removeItem(storageKey)
                    : storage.setItem(storageKey, String(width))
            } catch {
                // A full or blocked quota is not worth failing a drag over.
            }
        }

        onresize?.(width)
    }

    const stored = (): number | null => {
        if (storage === null) {
            return null
        }

        try {
            const raw = Number.parseInt(storage.getItem(storageKey) ?? '', 10)

            return Number.isFinite(raw) ? clamp(raw) : null
        } catch {
            return null
        }
    }

    // ---- drag -------------------------------------------------------------

    const onPointerDown = (event: PointerEvent): void => {
        // Primary button only: a right-click on the handle is not a drag.
        if (event.button !== undefined && event.button !== 0) {
            return
        }

        event.preventDefault()

        start = {
            x: event.clientX,
            width: width ?? panel.getBoundingClientRect().width,
            grow: growSign(panel, dock),
        }

        handle.classList.add('is-dragging')
        panel.ownerDocument.documentElement.classList.add('ai-kit-resizing')

        // Keeps the pointer with the handle when it outruns a 4px target.
        handle.setPointerCapture?.(event.pointerId)
    }

    const onPointerMove = (event: PointerEvent): void => {
        if (start === null) {
            return
        }

        set(start.width + start.grow * (event.clientX - start.x), false)
    }

    const onPointerUp = (): void => {
        if (start === null) {
            return
        }

        start = null

        handle.classList.remove('is-dragging')
        panel.ownerDocument.documentElement.classList.remove('ai-kit-resizing')

        // Persisted on release, not on every frame: one write per resize.
        set(width, true)
    }

    // Arrow keys move the same logical direction as the drag, so the keyboard
    // path is not a mirror-image of the mouse path in Arabic.
    const onKeyDown = (event: KeyboardEvent): void => {
        const physical = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0

        if (physical === 0) {
            return
        }

        event.preventDefault()

        const from = width ?? panel.getBoundingClientRect().width

        set(from + growSign(panel, dock) * physical * step, true)
    }

    // ---- breakpoint -------------------------------------------------------

    const query = matchMediaOrNull(media)
    let attached = false

    const attach = (): void => {
        if (attached) {
            return
        }

        attached = true

        handle.addEventListener('pointerdown', onPointerDown)
        handle.addEventListener('keydown', onKeyDown)
        panel.ownerDocument.addEventListener('pointermove', onPointerMove)
        panel.ownerDocument.addEventListener('pointerup', onPointerUp)
        panel.ownerDocument.addEventListener('pointercancel', onPointerUp)
    }

    const detach = (): void => {
        attached = false

        handle.removeEventListener('pointerdown', onPointerDown)
        handle.removeEventListener('keydown', onKeyDown)
        panel.ownerDocument.removeEventListener('pointermove', onPointerMove)
        panel.ownerDocument.removeEventListener('pointerup', onPointerUp)
        panel.ownerDocument.removeEventListener('pointercancel', onPointerUp)
    }

    const sync = (): void => {
        if (query === null || query.matches) {
            attach()
            set(stored(), false)

            return
        }

        detach()
        set(null, false)
    }

    if (!handle.hasAttribute('role')) {
        handle.setAttribute('role', 'separator')
    }

    handle.setAttribute('aria-orientation', 'vertical')
    handle.setAttribute('aria-valuemin', String(min))
    handle.setAttribute('aria-valuemax', String(max))

    if (!handle.hasAttribute('tabindex')) {
        handle.setAttribute('tabindex', '0')
    }

    sync()
    query?.addEventListener?.('change', sync)

    return {
        width: () => width,
        resize: (next: number) => {
            if (query === null || query.matches) {
                set(next, true)
            }
        },
        reset: () => set(null, true),
        destroy: () => {
            detach()
            query?.removeEventListener?.('change', sync)
        },
    }
}

/**
 * Which way the pointer has to travel for the panel to grow: the handle is on
 * the panel's inline-start edge when it is docked at the inline end, and the
 * inline start is physically LEFT in LTR and RIGHT in RTL.
 *
 * Read per drag, from the panel itself: an app that flips locale without
 * remounting the sidebar still resizes the right way round.
 */
const growSign = (panel: HTMLElement, dock: 'inline-start' | 'inline-end'): 1 | -1 =>
    (dock === 'inline-end') === (readDirection(panel) === 'ltr') ? -1 : 1

/**
 * The panel's writing direction. The nearest explicit `dir` wins — that is
 * where an app declares it, usually on `<html>` — with the computed style as
 * the fallback for a direction set in CSS alone.
 */
export function readDirection(element: HTMLElement): 'ltr' | 'rtl' {
    const declared = element.closest?.('[dir]')?.getAttribute('dir')?.toLowerCase()

    if (declared === 'rtl' || declared === 'ltr') {
        return declared
    }

    const computed = element.ownerDocument?.defaultView
        ?.getComputedStyle?.(element)
        ?.direction?.toLowerCase()

    return computed === 'rtl' ? 'rtl' : 'ltr'
}

const defaultApply = (width: number | null, panel: HTMLElement): void => {
    panel.style.width = width === null ? '' : `${width}px`
}

const matchMediaOrNull = (media: string): MediaQueryList | null => {
    // No matchMedia (an SSR-shaped global, an old jsdom) means no breakpoint
    // to test, so the helper stays live rather than silently dead.
    if (typeof globalThis.matchMedia !== 'function') {
        return null
    }

    return globalThis.matchMedia(media)
}

const localStorageOrNull = (): Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null => {
    try {
        return globalThis.localStorage ?? null
    } catch {
        return null
    }
}

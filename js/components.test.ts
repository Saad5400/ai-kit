import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { compile } from 'svelte/compiler'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'

/**
 * The components ship as source — there is no build step to catch a typo
 * in them, so the suite compiles each one the way a consuming app's Vite
 * will. Behaviour lives in the core tests; this is the smoke alarm.
 */
const read = (path: string) => readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')

describe('vue components', () => {
    it.each([
        'ApprovalCard.vue',
        'ApprovalFields.vue',
        'Markdown.vue',
        'ProcessGroup.vue',
        'QuestionCard.vue',
        'ThinkingDisclosure.vue',
        'ToolChip.vue',
    ])('compiles %s', (file) => {
        const source = read(`./vue/${file}`)
        const { descriptor, errors } = parse(source, { filename: file })

        expect(errors).toEqual([])

        const script = compileScript(descriptor, { id: file })

        expect(script.content).toContain('setup(')

        const template = compileTemplate({
            source: descriptor.template!.content,
            filename: file,
            id: file,
        })

        expect(template.errors).toEqual([])
    })
})

describe('the chips compile their progress rendering', () => {
    // The smoke test above already compiles the new markup; these pin the
    // progress surface itself, so stripping the bar or the LTR-isolated
    // count in a refactor fails loudly rather than silently shipping a
    // chip that ignores `progress`.
    it('ToolChip.vue renders the bar, the label and the LTR count', () => {
        const source = read('./vue/ToolChip.vue')
        const { descriptor } = parse(source, { filename: 'ToolChip.vue' })
        const template = compileTemplate({
            source: descriptor.template!.content,
            filename: 'ToolChip.vue',
            id: 'ToolChip.vue',
        })

        expect(template.errors).toEqual([])

        for (const marker of ['progressbar', 'ai-kit-tool-chip__bar', 'ai-kit-tool-chip__count', 'ai-kit-tool-chip__label']) {
            expect(template.code).toContain(marker)
        }

        expect(descriptor.template!.content).toContain('dir="ltr"')
    })

    it('ToolChip.svelte renders the bar, the label and the LTR count', () => {
        const source = read('./svelte/ToolChip.svelte')
        const { js } = compile(source, { filename: 'ToolChip.svelte', generate: 'client' })

        for (const marker of ['progressbar', 'ai-kit-tool-chip__bar', 'ai-kit-tool-chip__count', 'ai-kit-tool-chip__label']) {
            expect(js.code).toContain(marker)
        }

        expect(source).toContain('dir="ltr"')
    })

    it.each([
        ['./vue/ProcessGroup.vue'],
        ['./svelte/ProcessGroup.svelte'],
    ])('%s hands progress through to the chip and swaps the live summary', (file) => {
        const source = read(file)

        expect(source).toContain('progress')
        expect(source).toContain('summary')
    })
})

/**
 * The v0.9.0 card redesign (owner ruling #22), pinned per defect the prod
 * screenshots showed. The compile checks above already catch a typo; these
 * catch a REGRESSION — a refactor that drops a `<bdi>`, turns a chip back into
 * a text run, or reintroduces a physical `margin-left` that breaks Arabic.
 */
describe('the redesigned cards', () => {
    /** Markup + styles, without the docblock and the props. */
    const body = (file: string): string => read(file).split('</script>').slice(1).join('</script>')

    it.each([
        ['./svelte/QuestionCard.svelte', 'card.question'],
        ['./vue/QuestionCard.vue', 'card.question'],
    ])('%s isolates the question and every option chip', (file) => {
        const markup = body(file)

        // The question itself, and each option, inside a bidi isolate.
        expect(markup).toMatch(/<bdi dir="auto">\{\{? ?card\.question ?\}?\}<\/bdi>/)
        expect(markup).toMatch(/<bdi dir="auto">\{\{? ?option ?\}?\}<\/bdi>/)
    })

    it.each([['./svelte/QuestionCard.svelte'], ['./vue/QuestionCard.vue']])(
        '%s renders the options as real buttons with a chosen state',
        (file) => {
            const markup = body(file)

            expect(markup).toContain('ai-kit-question__option')
            expect(markup).toContain('type="button"')
            // Choosing has to READ as a choice: pressed state, not just a
            // disappearing text run.
            expect(markup).toContain('aria-pressed')
            expect(markup).toContain('is-chosen')
        },
    )

    it.each([['./svelte/QuestionCard.svelte'], ['./vue/QuestionCard.vue']])(
        '%s gives the answer row a send button and a quiet skip',
        (file) => {
            const markup = body(file)

            expect(markup).toContain('type="submit"')
            expect(markup).toContain('ai-kit-question__send')
            expect(markup).toContain('ai-kit-question__skip')
            // The underlined text scrap is gone.
            expect(markup).not.toContain('text-decoration: underline')
        },
    )

    it.each([['./svelte/ApprovalCard.svelte'], ['./vue/ApprovalCard.vue']])(
        '%s renders the title once, isolated, and the badge as a badge',
        (file) => {
            const markup = body(file)

            expect(markup).toMatch(/<bdi dir="auto">\{\{? ?card\.title ?\}?\}<\/bdi>/)
            // Exactly one place prints the title; the duplicate preview line is
            // dropped in `previewLines()`, not here.
            expect(markup.match(/card\.title/g)).toHaveLength(1)
            expect(markup).toContain('ai-kit-card__badge')
            expect(markup).toContain('ai-kit-card__dot')
        },
    )

    it.each([['./svelte/ApprovalCard.svelte'], ['./vue/ApprovalCard.vue']])(
        '%s takes its preview lines from the deduping helper',
        (file) => {
            expect(read(file)).toContain("previewLines } from '../core/cards'")
        },
    )

    it.each([['./svelte/ApprovalFields.svelte'], ['./vue/ApprovalFields.vue']])(
        '%s collapses identity fields into a disclosure and localizes empties',
        (file) => {
            const source = read(file)

            expect(source).toContain('partitionFields')
            expect(body(file)).toContain('ai-kit-fields__details')
            expect(body(file)).toContain('ai-kit-fields__summary')
            // The empty placeholder is a prop, threaded into displayValue.
            expect(source).toContain('emptyLabel')
            expect(body(file)).toMatch(/displayValue\((field|entry)\.value, emptyLabel\)/)
        },
    )

    it.each([
        ['./svelte/QuestionCard.svelte'],
        ['./vue/QuestionCard.vue'],
        ['./svelte/ApprovalCard.svelte'],
        ['./vue/ApprovalCard.vue'],
        ['./svelte/ApprovalFields.svelte'],
        ['./vue/ApprovalFields.vue'],
    ])('%s lays out with logical properties only', (file) => {
        // A physical side is a card that lays out backwards in Arabic.
        expect(body(file)).not.toMatch(
            /(margin|padding|border|inset)-(left|right)|text-align:\s*(left|right)/,
        )
    })

    it.each([
        ['./svelte/QuestionCard.svelte'],
        ['./vue/QuestionCard.vue'],
        ['./svelte/ApprovalCard.svelte'],
        ['./vue/ApprovalCard.vue'],
        ['./svelte/ApprovalFields.svelte'],
        ['./vue/ApprovalFields.vue'],
    ])('%s keeps token colors out of the border and outline shorthands', (file) => {
        // A `--ai-kit-*` token mapped to raw HSL channels is not a color, and
        // it takes the WHOLE shorthand down with it — which is how the prod
        // card lost every border and fill at once.
        expect(body(file)).not.toMatch(/\n\s*(border|outline):\s*[^;]*var\(/)
    })

    it.each([
        ['./svelte/QuestionCard.svelte'],
        ['./vue/QuestionCard.vue'],
        ['./svelte/ApprovalCard.svelte'],
        ['./vue/ApprovalCard.vue'],
        ['./svelte/ApprovalFields.svelte'],
        ['./vue/ApprovalFields.vue'],
    ])('%s bakes no user-facing copy into its markup', (file) => {
        // Every string is a prop (the v0.7.2 lesson: not even an ellipsis).
        expect(body(file)).not.toContain('…')
        expect(body(file)).not.toMatch(/[؀-ۿ]/)
    })
})

describe('the resizable sidebar helper', () => {
    it.each([['./svelte/resizable.ts'], ['./vue/resizable.ts']])(
        '%s wraps the framework-free core rather than reimplementing it',
        (file) => {
            const source = read(file)

            expect(source).toContain("from '../core/resizable'")
            expect(source).toContain('createResizable')
            // Both adapters have to tear the helper down with the component.
            expect(source).toContain('destroy')
        },
    )

    it('ships the two load-bearing handle rules', () => {
        const css = read('./styles/resizable.css')

        expect(css).toContain('cursor: col-resize')
        expect(css).toContain('touch-action: none')
        expect(css).toContain('.ai-kit-resizing')
        // Positioned by the app, but never with a physical side of our own.
        expect(css).not.toMatch(/(margin|padding|border|inset)-(left|right)/)
    })
})

describe('svelte components', () => {
    it.each([
        'ApprovalCard.svelte',
        'ApprovalFields.svelte',
        'Markdown.svelte',
        'ProcessGroup.svelte',
        'QuestionCard.svelte',
        'ThinkingDisclosure.svelte',
        'ToolChip.svelte',
    ])('compiles %s', (file) => {
        const { js, warnings } = compile(read(`./svelte/${file}`), {
            filename: file,
            generate: 'client',
        })

        expect(js.code.length).toBeGreaterThan(0)
        expect(warnings.filter((warning) => warning.code !== 'a11y_no_redundant_roles')).toEqual([])
    })
})

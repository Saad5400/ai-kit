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

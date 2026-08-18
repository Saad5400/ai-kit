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
    it.each(['Markdown.vue', 'ThinkingDisclosure.vue', 'ToolChip.vue'])('compiles %s', (file) => {
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

describe('svelte components', () => {
    it.each(['Markdown.svelte', 'ThinkingDisclosure.svelte', 'ToolChip.svelte'])('compiles %s', (file) => {
        const { js, warnings } = compile(read(`./svelte/${file}`), {
            filename: file,
            generate: 'client',
        })

        expect(js.code.length).toBeGreaterThan(0)
        expect(warnings.filter((warning) => warning.code !== 'a11y_no_redundant_roles')).toEqual([])
    })
})

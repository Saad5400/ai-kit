import { defineConfig } from 'vitest/config'

export default defineConfig({
    test: {
        // The components mount into a real DOM, and the markdown tests parse
        // the sanitized output back to check what a browser would make of it.
        // The pipeline itself no longer needs a DOM — `rehype-sanitize` works
        // on the hast tree — so this is the components' requirement now, not
        // the sanitizer's.
        environment: 'jsdom',
        include: ['js/**/*.test.ts'],
    },
})

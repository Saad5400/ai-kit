import { defineConfig } from 'vitest/config'

export default defineConfig({
    test: {
        // The markdown pipeline sanitizes through a real DOM. jsdom, not
        // happy-dom: happy-dom's NodeIterator makes DOMPurify strip the
        // root element of every fragment, so the sanitizer under test
        // would not be the sanitizer that ships.
        environment: 'jsdom',
        include: ['js/**/*.test.ts'],
    },
})

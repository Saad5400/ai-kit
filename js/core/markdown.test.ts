import { describe, expect, it, vi } from 'vitest'
import { createLiveRenderer, escapeHtml, preWrap, renderMarkdown } from './markdown'

describe('renderMarkdown', () => {
    it('renders a heading that has no blank line after it', () => {
        // uqucc's hand-rolled parser swallowed the paragraph here.
        const html = renderMarkdown('# Title\nbody text')

        expect(html).toContain('<h1>Title</h1>')
        expect(html).toContain('body text')
    })

    it('renders nested lists as nested markup', () => {
        const html = renderMarkdown('- one\n  - inner\n- two')

        expect(html).toContain('<ul>')
        expect(html).toMatch(/<li>[\s\S]*inner[\s\S]*<\/li>/)
        // The inner item lives inside a second list, not as a sibling.
        expect(html.match(/<ul>/g)).toHaveLength(2)
    })

    it('renders GFM tables', () => {
        const html = renderMarkdown('| a | b |\n|---|---|\n| 1 | 2 |')

        expect(html).toContain('<table>')
        expect(html).toContain('<th>a</th>')
        expect(html).toContain('<td>2</td>')
    })

    it('renders GFM strikethrough', () => {
        expect(renderMarkdown('~~gone~~')).toContain('<del>gone</del>')
    })

    it('renders fenced code as escaped text inside a code block', () => {
        const html = renderMarkdown('```js\nconst a = 1 < 2\n```')

        expect(html).toContain('<pre>')
        expect(html).toContain('class="language-js"')
        expect(html).toContain('const a = 1 &lt; 2')
    })

    it('escapes raw html to literal text instead of rendering it', () => {
        const html = renderMarkdown('before <script>alert(1)</script> after')

        expect(html).not.toContain('<script>')
        // The characters the model wrote survive as literal text — the
        // tag is neither executed nor silently dropped. Only `<` needs
        // escaping in text content; a literal `>` cannot open anything.
        expect(html).toBe('<p>before &lt;script>alert(1)&lt;/script> after</p>')
    })

    it('escapes raw block-level html too', () => {
        const html = renderMarkdown('<div onclick="steal()">hi</div>\n\nparagraph')

        expect(html).not.toContain('<div')
        expect(html).toContain('&lt;div onclick="steal()">hi&lt;/div>')
        expect(html).toContain('<p>paragraph</p>')
    })

    it('parses the escaped html back to exactly the characters the model wrote', () => {
        const source = '<img src=x onerror=alert(1)> & <b>bold</b>'
        const host = document.createElement('div')

        host.innerHTML = renderMarkdown(source)

        expect(host.querySelector('img')).toBeNull()
        expect(host.textContent).toBe(source)
    })

    it('sends every link to a new tab, with the referrer and ranking dropped', () => {
        const html = renderMarkdown('[docs](https://example.com)')

        expect(html).toContain('target="_blank"')
        expect(html).toContain('rel="noopener noreferrer nofollow"')
        expect(html).toContain('href="https://example.com"')
    })

    it('strips a javascript: link rather than rendering it', () => {
        const html = renderMarkdown('[click](javascript:alert(1))')

        expect(html).not.toContain('javascript:')
        expect(html).toContain('click')
    })

    // Every scheme outside the allow list, not just the famous one — and the
    // link decorator must skip the stripped anchor rather than dressing an
    // hrefless element up as navigation.
    it.each([
        ['data:', '[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'],
        ['vbscript:', '[click](vbscript:msgbox(1))'],
        ['file:', '[click](file:///etc/passwd)'],
    ])('strips a %s link but keeps its text', (scheme, source) => {
        const html = renderMarkdown(source)

        expect(html).not.toContain(scheme)
        expect(html).not.toContain('href')
        expect(html).not.toContain('target="_blank"')
        expect(html).toContain('click')
    })

    it('keeps a mailto link, which is a scheme an answer legitimately uses', () => {
        const html = renderMarkdown('[mail](mailto:a@example.com)')

        expect(html).toContain('href="mailto:a@example.com"')
        expect(html).toContain('rel="noopener noreferrer nofollow"')
    })

    it('drops an unknown attribute a rehype plugin put on an element', () => {
        const inject = () => (tree: any) => {
            tree.children.push({
                type: 'element',
                tagName: 'p',
                properties: { onClick: 'steal()', style: 'position:fixed', className: ['ok'] },
                children: [],
            })
        }

        const html = renderMarkdown('x', { plugins: { rehype: [inject] } })

        expect(html).not.toContain('onclick')
        expect(html).not.toContain('position:fixed')
    })

    it('returns an empty string for empty source', () => {
        expect(renderMarkdown('')).toBe('')
    })

    it('degrades to escaped pre-wrap instead of throwing when rendering fails', () => {
        const plugins = {
            remark: [
                () => () => {
                    throw new Error('plugin exploded')
                },
            ],
        }

        const html = renderMarkdown('# <b>title</b>', { plugins })

        expect(html).toBe('<p style="white-space:pre-wrap"># &lt;b&gt;title&lt;/b&gt;</p>')
    })

    it('accepts extra remark and rehype plugins', () => {
        const shout = () => (tree: any) => {
            const walk = (node: any): void => {
                if (node.type === 'text') {
                    node.value = node.value.toUpperCase()
                }

                node.children?.forEach(walk)
            }

            walk(tree)
        }

        const stamp = () => (tree: any) => {
            tree.children.push({ type: 'element', tagName: 'hr', properties: {}, children: [] })
        }

        const html = renderMarkdown('hello', { plugins: { remark: [shout], rehype: [stamp] } })

        expect(html).toContain('HELLO')
        expect(html).toContain('<hr>')
    })
})

describe('escapeHtml / preWrap', () => {
    it('escapes the markup-significant characters', () => {
        expect(escapeHtml(`<a href="x">&'`)).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&#39;')
    })

    it('wraps the source so whitespace survives', () => {
        expect(preWrap('a\nb')).toBe('<p style="white-space:pre-wrap">a\nb</p>')
    })
})

describe('createLiveRenderer', () => {
    it('coalesces pushes onto the trailing edge of the throttle window', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 250 })

        live.push('He')
        live.push('Hell')
        live.push('Hello')

        expect(onHtml).not.toHaveBeenCalled()

        vi.advanceTimersByTime(250)

        expect(onHtml).toHaveBeenCalledTimes(1)
        expect(onHtml.mock.calls[0][0]).toContain('Hello')

        vi.useRealTimers()
    })

    it('paints the first push without waiting out a window', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 250 })

        live.push('first')

        // Scheduled with a zero delay, not a full window — a reply must not
        // look stalled for a quarter second before its first token shows.
        vi.advanceTimersByTime(0)

        expect(onHtml).toHaveBeenCalledTimes(1)
        expect(onHtml.mock.calls[0][0]).toContain('first')

        // The next one waits, so a fast stream cannot re-parse per delta.
        live.push('first second')
        vi.advanceTimersByTime(0)

        expect(onHtml).toHaveBeenCalledTimes(1)

        vi.advanceTimersByTime(250)

        expect(onHtml).toHaveBeenCalledTimes(2)

        vi.useRealTimers()
    })

    it('paints immediately again after an idle gap, such as a tool call', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 250 })

        live.push('before')
        vi.advanceTimersByTime(0)
        expect(onHtml).toHaveBeenCalledTimes(1)

        // The model pauses to run a tool, then resumes.
        vi.advanceTimersByTime(5000)
        live.push('before after')
        vi.advanceTimersByTime(0)

        expect(onHtml).toHaveBeenCalledTimes(2)

        vi.useRealTimers()
    })

    it('renders once per window while the text keeps growing', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 100 })

        live.push('a')
        vi.advanceTimersByTime(100)
        live.push('ab')
        vi.advanceTimersByTime(100)

        expect(onHtml).toHaveBeenCalledTimes(2)

        vi.useRealTimers()
    })

    it('skips a re-render when the text has not changed', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 100 })

        live.push('same')
        vi.advanceTimersByTime(100)
        live.push('same')
        vi.advanceTimersByTime(100)

        expect(onHtml).toHaveBeenCalledTimes(1)

        vi.useRealTimers()
    })

    it('degrades to pre-wrap past the live character limit', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10, liveCharLimit: 20 })

        live.push('# heading '.repeat(10))
        vi.advanceTimersByTime(10)

        expect(onHtml.mock.calls[0][0]).toContain('white-space:pre-wrap')
        expect(onHtml.mock.calls[0][0]).not.toContain('<h1>')

        vi.useRealTimers()
    })

    it('always does one full render on finish, even past the limit', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10, liveCharLimit: 5 })

        live.push('# heading')
        vi.advanceTimersByTime(10)
        live.finish()

        expect(onHtml).toHaveBeenCalledTimes(2)
        expect(onHtml.mock.calls[1][0]).toContain('<h1>heading</h1>')

        vi.useRealTimers()
    })

    it('finishes without waiting out a pending window', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 5000 })

        live.push('done text')
        live.finish()

        expect(onHtml).toHaveBeenCalledTimes(1)
        expect(onHtml.mock.calls[0][0]).toContain('done text')

        vi.advanceTimersByTime(5000)

        expect(onHtml).toHaveBeenCalledTimes(1)

        vi.useRealTimers()
    })

    it('closes markdown the model has not finished writing yet', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10 })

        live.push('this is **bold')
        vi.advanceTimersByTime(10)

        // Without the mid-stream repair the two asterisks render as literal
        // characters for one frame and then vanish when the pair arrives.
        expect(onHtml.mock.calls[0][0]).toContain('<strong>bold</strong>')
        expect(onHtml.mock.calls[0][0]).not.toContain('**')

        vi.useRealTimers()
    })

    it('closes an unterminated inline code span and strikethrough mid-stream', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10 })

        live.push('run `npm i')
        vi.advanceTimersByTime(10)
        expect(onHtml.mock.calls[0][0]).toContain('<code>npm i</code>')

        live.push('run `npm i` then ~~old')
        vi.advanceTimersByTime(10)
        expect(onHtml.mock.calls[1][0]).toContain('<del>old</del>')

        vi.useRealTimers()
    })

    it('renders the finished text as written, with nothing the repair invented', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10 })

        // A turn that really did end on a stray asterisk keeps it: `finish()`
        // renders the source, not the mended buffer.
        live.push('rate is 5 * ')
        live.finish()

        expect(onHtml.mock.lastCall?.[0]).toContain('5 *')
        expect(onHtml.mock.lastCall?.[0]).not.toContain('<em>')

        vi.useRealTimers()
    })

    it('leaves a finished code fence alone rather than repairing inside it', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10 })

        live.push('```js\nconst a = b ** 2\n')
        vi.advanceTimersByTime(10)

        expect(onHtml.mock.calls[0][0]).toContain('const a = b ** 2')

        vi.useRealTimers()
    })

    it('stops emitting once disposed', () => {
        vi.useFakeTimers()

        const onHtml = vi.fn()
        const live = createLiveRenderer({ onHtml, throttleMs: 10 })

        live.push('text')
        live.dispose()
        vi.advanceTimersByTime(100)
        live.finish()

        expect(onHtml).not.toHaveBeenCalled()

        vi.useRealTimers()
    })
})

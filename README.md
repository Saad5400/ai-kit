# saad/ai-kit

Shared AI infrastructure for **catodemy**, **s-grade** and **uqucc-laravel**. Public repo, consumed as a composer VCS package; the sole requirer of `laravel/ai` and `laravel/mcp` — apps depend on this instead.

This README describes the finished product. Owner decision record (the authority): [`docs/DECISIONS.md`](docs/DECISIONS.md) · working milestone plan: [`docs/PLAN.md`](docs/PLAN.md)

## Modules

Toggled per app via `config/ai-kit.php` → `modules.*`:

| Module | Default | Contents |
|---|---|---|
| `gateway` | on | Canonical `ReasoningOpenRouterGateway`, resilience policy (timeouts, retries, server-side model routing, circuit breaker), audio attachments as `input_audio`, drift-guard |
| `agents` | on | Agent ↔ MCP tool adapters, Capability bridge |
| `conversations` | on | `EncryptedConversationStore`, retention policies, tool traces |
| `streaming` | on | `TurnRunner` + sinks, resumable SSE buffer (queue-worker generation) |
| `approvals` | on | `Capability` + `Effect`, classified pause on laravel/ai `Approvable`, server-built card form schema (`Field`/`FieldWidget`) + `guardEdits`, undo ledger + `UndoTurn`, `AskUser` |
| `attachments` | on | 3-stage extraction pipeline (born-digital text layer, junk + Arabic-reversal probes, vision fallback), extract-on-upload, sha-256 cache |
| `usage` | on | `TurnSpend`, canonical usage events, turn traces + TTFT metrics |
| `catalog` | on | `CatalogSource` (config and/or DB), `ai-kit:sync-models`, `ModelRouting` |
| `safety` | on | Central kill switch, `BudgetGuard`, concurrency caps, degraded mode |
| `rag` | off | Hybrid retriever (pgvector + RRF), embedder/chunker |
| `credits` | off | Generalized wallets, `CreditCalculator`, idempotent meter base |

`Saad\AiKit\Testing` ships fakes + exported contract test suites for apps (dev-only, not a toggle).

## The wire contract

One turn is one SSE stream of `event: NAME\ndata: {json}\n\n` frames, written by `SseStream` and folded out of the provider stream by `StreamEventMapper`. The inline path and the resumable `TurnBuffer` path emit the same sequence for the same turn, so a client works against either.

| Event | Payload | Notes |
|---|---|---|
| `delta` | `{text}` | Model text. Deltas concatenate. |
| `reasoning` | `{text}` | Thinking. **On by default.** No start/end events — the client opens its block on the first `reasoning` and closes it on the first following `delta`, `tool` or terminal event. |
| `tool` | `{id, name, status: running\|done, successful?}` | **On by default.** Arguments and results never reach the wire; hook `ToolCall` server-side if an app wants more. |
| `approval` | `{kind, id, tool, title, destructive, undoable, editable, arguments, fields, preview, reason}` | A paused turn's card, every trust-bearing field server-derived. `id` is the tool call's id, so a paused call's `running` chip folds into its card. `fields` is the form schema (see below); the flat `arguments` map is deprecated but still sent. |
| `question` | `{kind, id, question, options?}` | An `AskUser` pause — answered, not approved. `options` carries 2–4 suggested answers when the model proposed any. |
| `citations` | `{items}` | Post-stream, from a `beforeDone` hook. |
| `done` | app-assembled | **Terminal.** |
| `error` | `{message}` | **Terminal** — no `done` ever follows it. |

A turn ends with exactly one terminal event. Buffered frames are led by an `id:` line carrying the sequence number to resume from. Pre-flight failures are plain JSON at 503/429/422/402, discriminated client-side by `Content-Type`.

Opt out of the defaults per app with `$mapper->withoutReasoning()` / `->withoutToolEvents()`; replace them with `->onReasoning(...)` or `->on(ToolCall::class, ...)`.

On the buffered path, `runIntoBuffer($stream, $buffer, $turnId, $meta)` also takes `$meta` as a closure — `fn (StreamResult $result): array` — for the facts that only exist once the fold is over (the turn's final cost, the id of the message just persisted). It runs after the stream drains and its return is the meta folded into the terminal frame.

**Cancelling a turn** is not a mapper hook: compose a generator in front of it, so the provider stream itself stops rather than its events being silenced.

```php
$untilCancelled = function (iterable $stream) use ($buffer, $turnId): Generator {
    foreach ($stream as $event) {
        if ($buffer->isCancelled($turnId)) {
            return;
        }

        yield $event;
    }
};

$mapper->runIntoBuffer($untilCancelled($stream), $buffer, $turnId, $meta);
```

A cancelled stream simply ends, so the fold takes its normal exit and the turn finishes on `done` with whatever it produced — a stop is a completed short turn, not an error. (`TurnBuffer::fail()` takes a fourth argument to append an empty `done` after `error`, for clients that hang their whole teardown off `done`. Off by default; the terminal contract above is what the kit promises.)

## Approval forms

An approval card describes its own form. `ClassifiedTool::fields()` declares the arguments a tool wants rendered a particular way; everything it leaves out is inferred from the pending value (`bool` → boolean, `int|float` → number, a string with a newline or over 120 chars → textarea, an array → readonly, otherwise text), and any argument named `id` or `*_id` is readonly because it addresses the record the write lands on:

```php
public function fields(): array
{
    return [
        'course_id' => FieldWidget::Readonly,
        'body' => Field::make('body', FieldWidget::Markdown, label: 'المحتوى'),
        'status' => ['widget' => 'select', 'options' => ['draft' => 'مسودة', 'published' => 'منشور']],
        'internal_note' => 'hidden',
        'summary' => Field::make('summary', FieldWidget::Textarea),  // optional: renders even unsent
    ];
}
```

Each field reaches the client as `{name, widget, editable, label, options, placeholder, value}`. A destructive (one-click) card renders every field readonly regardless of what the tool declared.

**The field flags are not the security boundary.** They live in the browser, where the user owns them; an edited `*_id` that reaches the tool repoints the write at a record no preview ever showed. `ApprovalCards::guardEdits()` is what makes the form safe — it returns the argument set to execute: the user's values for editable fields, the **original pending values** for readonly and hidden ones (silently restored), edited numbers and booleans cast back to their declared types, and an exception if the edit introduces an argument key the card never carried. Hand it to `ResumeDecisions::fromClient()` and it cannot be forgotten, because that is the only path from client input to `Decisions`:

```php
$pending = (new StoredApprovals)->pending($conversationId);   // the SERVER's pending set

$decisions = ResumeDecisions::fromClient(
    $request->validated('decisions'),
    $cards->editGuard($pending),          // guards every edit; throws on an id that is not pending
);

return $agent->continue($decisions);      // guarded arguments only
```

Resuming on a queue? A closure cannot travel in a job payload, so guard in the request and dispatch the plain result — `ResumeDecisions::guarded($input, $cards->editGuard($pending))` returns the same client-shaped decisions with every edit reconciled, having round-tripped them through `fromClient()` so an unreadable shape throws in the request rather than in the job. The job then resumes with a bare `fromClient($guarded)`.

`AskUser` participates: its `answer` is the one editable field, so the model's own `question` and `options` are restored from the pause rather than taken from the client. Its schema takes optional `options` (2–4 suggested answers, sanitized and capped server-side) and the tool description tells the model to send them only when the answer space is enumerable.

## Frontend layer

The same repo ships the client half, so adopting the kit also gets an app its AI frontend:

```bash
npm install github:Saad5400/ai-kit#semver:^0.6.0
```

```ts
import { readSseStream } from '@saad5400/ai-kit/sse'
import { createTimeline, groupSegments } from '@saad5400/ai-kit/timeline'
import { renderMarkdown } from '@saad5400/ai-kit/markdown'
import type { AiKitSseEvent } from '@saad5400/ai-kit/events'

import Markdown from '@saad5400/ai-kit/vue/Markdown.vue'          // uqucc
import Markdown from '@saad5400/ai-kit/svelte/Markdown.svelte'    // catodemy, s-grade

import '@saad5400/ai-kit/styles/prose.css'                        // optional
```

Contents: `events` (the table above, as TypeScript), `sse` (a reader for POST-response streams — `EventSource` cannot send a body — wrapping `eventsource-parser` for the framing and keeping the fetch/abort shell, a JSON-with-raw-fallback parse, a `maxBufferSize` cap, and one deliberate spec departure: a final frame the server never closed with a blank line is still dispatched), `timeline` (the ordered segment reducer, below), `fields` (the form-schema presentation helpers the two component sets share), `markdown` (unified + GFM, sanitized on the hast tree by `rehype-sanitize` so no DOM is needed, raw HTML escaped to literal text, every link `target="_blank" rel="noopener noreferrer nofollow"`, plus a throttled `createLiveRenderer` that runs `remend` over the partial buffer so a half-written `**bold` never flashes its asterisks), and `vue/` + `svelte/` components (`Markdown`, `ProcessGroup`, `ApprovalCard`, `ApprovalFields`, `QuestionCard`, `ToolChip`).

Theming is CSS variables only — set them once on a container and every component follows:

| Token | Default | Used for |
|---|---|---|
| `--ai-kit-accent` / `--ai-kit-accent-fg` | `#3b82f6` / `#fff` | confirm button, focus ring, answered marker |
| `--ai-kit-destructive` / `--ai-kit-destructive-fg` | `#ef4444` / `#fff` | destructive card border/tint/confirm, failed chip |
| `--ai-kit-muted` | `color-mix(currentColor 65%, transparent)` | labels, reasons, thinking text |
| `--ai-kit-border` | `color-mix(currentColor 22%, transparent)` | every border and rule |
| `--ai-kit-surface` | `color-mix(currentColor 4–12%, transparent)` | card and disclosure backgrounds |
| `--ai-kit-radius` | `0.5rem` | corners |
| `--ai-kit-code-font` / `--ai-kit-code-size` | mono stack / `0.8125rem` | machine names, ids, code and markdown editors |

The neutral defaults are mixed out of `currentColor` rather than hardcoded greys, so the components read correctly on a **dark** admin panel with no app CSS at all — mapping the tokens to your design system is refinement, not a prerequisite.

### The segment timeline

The wire already delivers `delta` / `reasoning` / `tool` / `approval` / `question` in true chronological order. What went wrong in every app was the *client* model: one accumulated reasoning string plus one accumulated text string cannot express "talked, thought, called a tool, talked again, thought again", so the thinking block ended up pinned to the top of the message. `createTimeline()` keeps a list of segments in arrival order instead.

Pass your framework's reactive array **in**, so every mutation goes through its proxy — the reducer mutates in place and never reassigns:

```ts
const segments = reactive<Segment[]>([])          // Vue;  Svelte 5: let segments = $state<Segment[]>([])
const timeline = createTimeline(segments)

await readSseStream(response, (event, data) => {
    timeline.push(event, data)                    // unknown events are ignored — pass everything
})
```

Merge rules: consecutive `delta`s merge into the trailing text segment and consecutive `reasoning`s into the trailing thinking segment; a `tool` event upserts **by id in place**, so a `running` chip stays where the call started and `done` updates it there; an `approval`/`question` card whose id matches an existing tool segment **replaces** it in place (the v0.5.0 fold rule — no spinner is left running behind a decision card); anything else appends.

Your message component then renders groups, not raw segments. `groupSegments()` collapses consecutive thinking and tool segments into one `process` group (a single steps disclosure) while `text` and `card` segments stay top-level in place — cards are never swallowed, because an approval card is a decision surface, not a progress detail:

```svelte
{#each groupSegments(segments) as group, i (i)}
    {#if group.type === 'text'}
        <Markdown value={group.text} />
    {:else if group.type === 'card'}
        {#if group.card.kind === 'question'}
            <QuestionCard card={group.card} answer={answers[group.card.id]} onanswer={answer} onskip={skip} />
        {:else}
            <ApprovalCard card={group.card} ondecide={(d) => decide(group.card.id, d)} />
        {/if}
    {:else}
        <ProcessGroup items={group.items} live={streaming && i === groups.length - 1} />
    {/if}
{/each}
```

`ProcessGroup` supersedes `ThinkingDisclosure` (deprecated, still exported for one version): a real `<details>` with a chevron and a tool-count badge, open while `live` and collapsing on its own once the group settles — until the user toggles it, after which their choice sticks.

`ApprovalCard` is the whole card: header with an `icon` slot, title, a status chip (`لا يمكن التراجع` / `قابل للتراجع`), the reason, preview lines, the form, and the confirm/reject row with confirm first in reading order so RTL puts it on the right. A destructive card takes the destructive accent on its border and confirm button, derived from the same server flag as the behaviour. Its `decide` event hands you exactly what `ResumeDecisions::fromClient()` accepts — `{action: 'approve'}`, `{action: 'edit', arguments}` or `{action: 'reject'}` — so the handler is one request.

`ApprovalFields` renders the field schema on its own if you want your own chrome: hidden skipped, readonly as a definition row (never a disabled input), the rest as their matching control, long text as an auto-growing editor that scrolls internally past ~40vh with a character count, and `markdown`/`code` in mono — each replaceable per widget through the `field` slot (Vue) or snippet (Svelte). Raw argument names render mono, `dir="ltr"` and bidi-**isolated**, which is what stops `action: create` from rendering as a scrambled "create action:" inside an Arabic card; a tool that supplies its own `label` gets `dir="auto"` prose instead.

`QuestionCard` takes an optional `answer` (or `skipped`) and settles into a record of what was actually answered rather than a bare "answered" label — pass it from your persisted thread and a reloaded page renders its history the same way.

`styles/prose.css` is optional and opt-in: a small flat prose sheet for `.ai-kit-markdown`, worth taking mainly because it uses logical properties throughout (`padding-inline-start`, `border-inline-start`, `text-align: start`), so Arabic replies lay out correctly with no mirrored RTL stylesheet. Map `--ai-kit-link`, `--ai-kit-border`, `--ai-kit-muted-bg` and `--ai-kit-muted` to your design tokens. An app with its own prose system should skip it.

It ships **source TypeScript with no build step**, so the consuming app's bundler compiles it. That means Vite (or an equivalent), and the package needs to reach the app's plugin pipeline rather than the dependency pre-bundler:

```js
// vite.config.js
optimizeDeps: { exclude: ['@saad5400/ai-kit'] },
ssr: { noExternal: ['@saad5400/ai-kit'] },   // Inertia SSR builds
```

## Development

```bash
composer install
composer test   # pest
composer lint   # pint --test

npm install
npm test        # vitest — js/core + a compile check on the components
```

Tests run on Orchestra Testbench; no live AI calls in CI (recorded fixtures only). The JS suite runs under jsdom for the components and for the markdown tests that parse the sanitized output back to check what a browser makes of it; the renderer itself no longer needs a DOM.

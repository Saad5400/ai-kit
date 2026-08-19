# saad/ai-kit

Shared AI infrastructure for **catodemy**, **s-grade** and **uqucc-laravel**. Public repo, consumed as a composer VCS package; the sole requirer of `laravel/ai` and `laravel/mcp` — apps depend on this instead.

This README describes the finished product. Owner decision record (the authority): [`docs/DECISIONS.md`](docs/DECISIONS.md) · working milestone plan: [`docs/PLAN.md`](docs/PLAN.md)

## Modules

Toggled per app via `config/ai-kit.php` → `modules.*`:

| Module | Default | Contents |
|---|---|---|
| `gateway` | on | Canonical `ReasoningOpenRouterGateway`, resilience policy (timeouts, retries, fallback chains, circuit breaker), drift-guard |
| `agents` | on | Agent ↔ MCP tool adapters, Capability bridge |
| `conversations` | on | `EncryptedConversationStore`, retention policies, tool traces |
| `streaming` | on | `TurnRunner` + sinks, resumable SSE buffer (queue-worker generation) |
| `approvals` | on | `Capability` + `Effect`, classified pause on laravel/ai `Approvable`, server-built card form schema (`Field`/`FieldWidget`) + `guardEdits`, undo ledger + `UndoTurn`, `AskUser` |
| `attachments` | on | 3-stage extraction pipeline, extract-on-upload, sha-256 cache |
| `usage` | on | `TurnSpend`, canonical usage events, turn traces + TTFT metrics |
| `catalog` | on | `CatalogSource` (config and/or DB), `ai-kit:sync-models` |
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

Contents: `events` (the table above, as TypeScript), `sse` (a dependency-free reader for POST-response streams — `EventSource` cannot send a body), `timeline` (the ordered segment reducer, below), `markdown` (unified + GFM, sanitized through DOMPurify, raw HTML escaped to literal text, every link `target="_blank" rel="noopener noreferrer nofollow"`, plus a throttled `createLiveRenderer` for streaming), and thin `vue/` + `svelte/` components (`Markdown`, `ProcessGroup`, `ApprovalFields`, `QuestionCard`, `ToolChip`) styled only through CSS-variable hooks — look and feel stays per app.

### The segment timeline

The wire already delivers `delta` / `reasoning` / `tool` / `approval` / `question` in true chronological order. What went wrong in every app was the *client* model: one accumulated reasoning string plus one accumulated text string cannot express "talked, thought, called a tool, talked again, thought again", so the thinking block ended up pinned to the top of the message. `createTimeline()` keeps a list of segments in arrival order instead.

Pass your framework's reactive array **in**, so every mutation goes through its proxy — the reducer mutates in place and never reassigns:

```ts
const segments = reactive<Segment[]>([])          // Vue;  Svelte 5: let segments = $state<Segment[]>([])
const timeline = createTimeline(segments)

for await (const { event, data } of readSseStream(response)) {
    timeline.push(event, data)                    // unknown events are ignored — pass everything
}
```

Merge rules: consecutive `delta`s merge into the trailing text segment and consecutive `reasoning`s into the trailing thinking segment; a `tool` event upserts **by id in place**, so a `running` chip stays where the call started and `done` updates it there; an `approval`/`question` card whose id matches an existing tool segment **replaces** it in place (the v0.5.0 fold rule — no spinner is left running behind a decision card); anything else appends.

Your message component then renders groups, not raw segments. `groupSegments()` collapses consecutive thinking and tool segments into one `process` group (a single steps disclosure) while `text` and `card` segments stay top-level in place — cards are never swallowed, because an approval card is a decision surface, not a progress detail:

```svelte
{#each groupSegments(segments) as group, i (i)}
    {#if group.type === 'text'}
        <Markdown value={group.text} />
    {:else if group.type === 'card'}
        {#if group.card.kind === 'question'}
            <QuestionCard card={group.card} onanswer={answer} onskip={skip} />
        {:else}
            <ApprovalFields fields={group.card.fields} onupdate={stage} />
        {/if}
    {:else}
        <ProcessGroup items={group.items} live={streaming && i === groups.length - 1} />
    {/if}
{/each}
```

`ProcessGroup` supersedes `ThinkingDisclosure`, which stays exported for one version and is deprecated. `ApprovalFields` renders the server's field schema — hidden fields skipped, readonly as label + value, the rest as their matching control, `markdown`/`code` as a monospace auto-growing textarea you can replace through the `field` slot (Vue) or snippet (Svelte) — and emits only the editable arguments, which is what an `{action: 'edit'}` decision sends.

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

Tests run on Orchestra Testbench; no live AI calls in CI (recorded fixtures only). The JS suite runs under jsdom, not happy-dom: happy-dom's `NodeIterator` makes DOMPurify strip the root element of every fragment, so the sanitizer under test would not be the sanitizer that ships.

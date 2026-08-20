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
| `tool` | `{id, name?, status: running\|done, successful?, progress?}` | **On by default.** Upserted by `id`. Extra `running` frames may carry `progress {label?, percent?, current?, total?}`; progress frames omit `name` — keep the one you hold. Arguments and results never reach the wire; hook `ToolCall` server-side if an app wants more. |
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

## Long turns

Anything the AI does may take a long time — a summary, a slide-deck translation, a 40-item classification — so the robustness lives in the general turn machinery, never in per-feature plumbing, and a long turn is still ONE chat turn: it stays open until it ends, the buffered tail (180 s hangup + cursor reconnect) stays the transport, and progress rides the existing `tool` event ([`docs/DECISIONS.md`](docs/DECISIONS.md) #24). What follows is the machinery that makes a ten-minute turn behave like a ten-second one.

**The buffer is a header plus pages.** `TurnBuffer` used to hold one record with an inline event list, so every appended delta re-read and re-wrote the whole log — O(n²) cache I/O that made a long turn slower per token the longer it ran. It is now a small header (`ai-kit:turn:{id}`: status, cursor, meta, heartbeat) plus pages of events (`ai-kit:turn:{id}:p{n}`, `page_size` entries each, default 64), so `append()` rewrites the header and at most one page whatever the turn's length. `eventsAfter()` reads only the pages past the cursor and `tail()` polls the header; `get()` keeps its return shape but is now the expensive read (it loads every page) — a controller that only checks uses `exists()` / `status()`, which read the header alone. Every write re-puts with the TTL so it slides for the life of the turn, records written before the split still read back, and the single-writer rule is unchanged: one producer per turn, ever.

**Heartbeat and the stale tail.** The header carries `heartbeat_at`, stamped by every append and refreshed between appends with `touch($turnId)` — the producer's proof of life while it is busy but silent inside a long tool call. A worker killed mid-turn (OOM, deploy, timeout) used to leave the record `running` and every client spinning until the TTL; now a tail that finds a running turn whose heartbeat is older than `stale_after_seconds` (default 300) writes the terminal itself — `error` with `$staleMessage` (a `tail()` argument; default `__('ai-kit::streaming.stale')`, shipped en + ar — apps pass their localized line), meta `stale: true` — behind an atomic claim so concurrent tailers write exactly one. Because `append()` refuses writes once a turn is no longer running, a producer that wakes up late cannot write past that terminal. `stale_trailing_done` appends an empty `done` after the stale `error`, for the clients that hang their teardown off `done` (the same trade-off as `fail()`'s trailing done, decided once at construction); a `stale_after_seconds` of 0 disables the check.

**`upsert()` keeps repeated state to one entry.** `upsert($turnId, $event, $data, $key = 'id')` appends — unless the LAST entry in the log is the same event about the same subject (`data[$key]` equal), in which case it replaces that entry in place with a fresh sequence number. A six-minute tool reporting three hundred times stays ONE log entry, yet a live or resuming client still receives the latest state because the seq advances; seqs stay ascending because only the tail entry is ever rewritten.

**Coalescing.** The mapper merges runs of consecutive `delta`s (and, separately, `reasoning`s) before they reach the sink — a run is released when its window elapses (default 100 ms), it reaches `maxChars` (default 400), an event of another kind arrives (which flushes held text FIRST, so wire order is unchanged), or the fold ends. The stage sits after the mapper's bookkeeping, so `StreamResult::$text` is byte-identical with or without it. The defaults differ by path, deliberately: ON on the buffered fold (`runBuffered()`, and therefore `runIntoBuffer()` and the `TurnRunner`), where every frame is a cache write and a log entry replayed to every resuming client; OFF on inline `run()`, where each token should hit the wire the moment it exists. `coalesce(windowMs, maxChars)` / `withoutCoalescing()` override either default.

**Tool progress.** A tool runs deep inside laravel/ai's tool loop with no path to the turn's sink, so `ToolProgress` is a static per-turn binding — bound by the runner before the fold, unbound in its finally, the same Octane-safety shape as `TurnContext`. A tool always just calls `current()`: when nothing is bound (an MCP call, a plain test) it gets a no-op instance, so tools need no environment checks.

```php
public function handle(Request $request): string
{
    $progress = ToolProgress::current()->for($request);      // pinned to this call's id

    $progress->each($items, function (Item $item): void {    // "1/40", "2/40", … + a cancel check per item
        $this->classify($item);
    }, label: 'Classifying');

    $progress->report(label: 'Uploading', percent: 80.0);    // or by hand — label, percent, current/total, any mix

    if ($progress->isCancelled()) {                          // hand-rolled loops poll this themselves
        return $this->partialResult();
    }
    // ...
```

Reports land on the wire as `tool {id, status: 'running', progress: {label?, percent?, current?, total?}}` — without `name`, which the client already holds — throttled to one emit per second per call id, except for what the user must not miss: a label change and the final state always emit. A skipped emit still touches the heartbeat, so a tool reporting every 100 ms keeps the turn visibly alive without growing the log; on the buffered path the runner routes progress frames to `upsert()`. Cancellation inside a tool is the read side of the same seam: `isCancelled()` polls the buffer's cancel flag (throttled to one cache read per second, sticky once true), and `each()` checks it per item and simply STOPS ITERATING — no exception, because laravel/ai's tool executor would only fold a throw into a tool-error string for the model to reason about. The tool returns what it accumulated, and the runner's cancel generator ends the turn on the next stream event.

**`TurnRunner`** is the DRY core of the background turn job every app wrote verbatim. It re-checks the kill switch where the spend would actually happen (a switch engaged mid-incident exists precisely to stop a queued backlog; the stream closure is invoked only after the guards pass, so a killed turn never opens a provider connection), labels the turn's usage rows via the feature `Context` key, authenticates the acting user for the whole fold (restored in the finally — a recycled worker never leaks it into the next job), binds `ToolProgress`, wraps the stream in the cancel generator (one cache read per second, each poll also touching the heartbeat), and routes the sink: non-terminal events to `append()`, progress frames to `upsert()` — re-stamped with the tool's remembered `name`, so a client resuming from cursor 0 never replays a nameless chip — and the terminal held back:

```php
$outcome = app(TurnRunner::class)->run(
    turnId: $turnId,
    stream: fn (): iterable => $agent->stream($prompt),    // invoked only after the guards pass
    mapper: app(StreamEventMapper::class)->onError(fn (): string => __('assistant.errors.generic')),
    buffer: $buffer,
    feature: 'assistant',                                  // kill-switch scope + usage Context label
    actingAs: $user,
    failMessage: fn (?Throwable $e): string => __('assistant.errors.generic'),
);

if ($outcome->failed) {
    $buffer->fail($turnId, $outcome->failure, $meta);      // the APP writes the terminal…
} else {
    $buffer->finish($turnId, $donePayload, $meta);         // …because its payload only exists after the fold
}
```

`TurnOutcome` carries the `StreamResult`, `cancelled` and `failed` as independent axes (a stop is a completed short turn with partial text, never an error), the resolved `failure` line, the `exception` when a throw ended the turn, and the mapper's assembled `done` payload for apps that build on it. What stays app-side, deliberately: model/user resolution, prompt assembly, metering, per-turn spend reset (catodemy's `TurnProviderSpend` is an app-level accumulator; the kit has no equivalent to reset), and — above all — the terminal event, because a completion payload (credit outcome, grounding, persisted message id) only exists app-side and only after the fold. `runIntoBuffer()` remains for apps that want the terminal written for them.

Three `streaming` config keys tune all of this — `page_size` (64), `stale_after_seconds` (300), `stale_trailing_done` (false) — and the provider wires them into the `TurnBuffer` it binds. The client half is `resumeTurn()`: see *Resuming a long turn* under the frontend layer.

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
import { resumeTurn } from '@saad5400/ai-kit/resume'
import { renderMarkdown } from '@saad5400/ai-kit/markdown'
import type { AiKitSseEvent } from '@saad5400/ai-kit/events'

import Markdown from '@saad5400/ai-kit/vue/Markdown.vue'          // uqucc
import Markdown from '@saad5400/ai-kit/svelte/Markdown.svelte'    // catodemy, s-grade

import '@saad5400/ai-kit/styles/prose.css'                        // optional
```

Contents: `events` (the table above, as TypeScript), `sse` (a reader for POST-response streams — `EventSource` cannot send a body — wrapping `eventsource-parser` for the framing and keeping the fetch/abort shell, a JSON-with-raw-fallback parse, a `maxBufferSize` cap, and one deliberate spec departure: a final frame the server never closed with a blank line is still dispatched), `timeline` (the ordered segment reducer, below), `resume` (the resumable-turn reader for the buffered path, below), `fields` (the form-schema presentation helpers the two component sets share), `markdown` (unified + GFM, sanitized on the hast tree by `rehype-sanitize` so no DOM is needed, raw HTML escaped to literal text, every link `target="_blank" rel="noopener noreferrer nofollow"`, plus a throttled `createLiveRenderer` that runs `remend` over the partial buffer so a half-written `**bold` never flashes its asterisks), and `vue/` + `svelte/` components (`Markdown`, `ProcessGroup`, `ApprovalCard`, `ApprovalFields`, `QuestionCard`, `ToolChip`).

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
| `--ai-kit-progress` | `var(--ai-kit-accent)` | the tool chip's determinate progress bar |

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

The `tool` upsert follows the v0.8.0 progress contract: a frame without `name` keeps the name already held (progress frames may omit it — never blank the chip), a frame carrying `progress` replaces the held progress **wholesale** (no per-field merge), a `running` frame without `progress` keeps what is held, and the `done` frame drops it — a settled chip never keeps showing `12/40`.

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

**Tool progress.** A long-running tool reports through `Saad\AiKit\Streaming\ToolProgress` server-side, which lands on the wire as extra `tool {status: 'running'}` frames carrying `progress: {label?, percent?, current?, total?}` — present only while running, upserted by `id`. `ToolChip` takes the segment's `progress` and renders the `label` (`dir="auto"`) after the tool name, `current/total` as `12/40` inside an LTR-isolated span (an Arabic host must not flip the digits into `40/12`), and — when `percent` or `current`/`total` gives it a figure — a thin determinate bar in place of the indeterminate spinner. The bar's fill color is `--ai-kit-progress`, defaulting to the accent; reduced motion disables its width transition the way it already slows the spinner. While a `ProcessGroup` is `live`, its summary line swaps the static label for the **last running** chip's progress label, so a collapsed disclosure reads "Grading submissions" instead of a generic "steps"; it falls back to the static label when no running chip carries one.

`ProcessGroup` supersedes `ThinkingDisclosure` (deprecated, still exported for one version): a real `<details>` with a chevron and a tool-count badge, open while `live` and collapsing on its own once the group settles — until the user toggles it, after which their choice sticks.

`ApprovalCard` is the whole card: header with an `icon` slot, title, a status chip (`لا يمكن التراجع` / `قابل للتراجع`), the reason, preview lines, the form, and the confirm/reject row with confirm first in reading order so RTL puts it on the right. A destructive card takes the destructive accent on its border and confirm button, derived from the same server flag as the behaviour. Its `decide` event hands you exactly what `ResumeDecisions::fromClient()` accepts — `{action: 'approve'}`, `{action: 'edit', arguments}` or `{action: 'reject'}` — so the handler is one request.

`ApprovalFields` renders the field schema on its own if you want your own chrome: hidden skipped, readonly as a definition row (never a disabled input), the rest as their matching control, long text as an auto-growing editor that scrolls internally past ~40vh with a character count, and `markdown`/`code` in mono — each replaceable per widget through the `field` slot (Vue) or snippet (Svelte). Raw argument names render mono, `dir="ltr"` and bidi-**isolated**, which is what stops `action: create` from rendering as a scrambled "create action:" inside an Arabic card; a tool that supplies its own `label` gets `dir="auto"` prose instead.

`QuestionCard` takes an optional `answer` (or `skipped`) and settles into a record of what was actually answered rather than a bare "answered" label — pass it from your persisted thread and a reloaded page renders its history the same way.

### Resuming a long turn

`resumeTurn()` (from `@saad5400/ai-kit/resume`) is the client half of the buffered path — the resume logic that lived in catodemy's `chat-state`, extracted so every app stops rewriting it. Point it at the app's stream route and feed the frames to the timeline:

```ts
const stream = resumeTurn({
    url: (cursor) => `/ai/turns/${turnId}/stream?cursor=${cursor}`,   // the app owns the route + locale prefix
    onEvent: (event, data, seq) => timeline.push(event, data),
    onLost: (reason) => showError(reason === 'expired' ? '…' : '…'),  // 'expired' | 'failed' | 'gone'
    onSilence: () => (waiting = true),                                // the "still processing" line; clear it in onEvent
})

// stream.cursor — the last buffer sequence seen
// stream.done   — resolves when the reader stopped, for ANY reason; never rejects
stream.stop()    // the user's stop, or a new turn replacing this one
```

Semantics: the cursor is taken from each frame's `id:` and re-issued on **every** attempt, so a reconnect replays only what this client has not folded. A read that ends without a terminal event — the server's own hangup ceiling, a broken connection, a rejected `fetch`, a non-ok status — counts one failure and retries after `backoffMs(n)` (default `min(1000·2^(n−1), 8000)`); any frame carrying an `id` resets the count, so a ten-minute turn that hangs up every 180 s never exhausts its retries, while a dead tail gives up after `maxConsecutiveFailures` (default 8) with `onLost('failed')`. A 404 is `onLost('expired')` on the spot — the buffer is gone. A terminal `done`/`error` frame resolves `done` and stops. `onSilence(silentMs)` fires once per `silenceMs` (default 20 000) window with no frames at all and is re-armed by any frame. `fetch` is injectable for tests.

`styles/prose.css` is optional and opt-in: a small flat prose sheet for `.ai-kit-markdown`, worth taking mainly because it uses logical properties throughout (`padding-inline-start`, `border-inline-start`, `text-align: start`), so Arabic replies lay out correctly with no mirrored RTL stylesheet. Map `--ai-kit-link`, `--ai-kit-border`, `--ai-kit-muted-bg` and `--ai-kit-muted` to your design tokens. An app with its own prose system should skip it.

It ships **source TypeScript with no build step**, so the consuming app's bundler compiles it. That means Vite (or an equivalent), and the package needs to reach the app's plugin pipeline rather than the dependency pre-bundler:

```js
// vite.config.js
optimizeDeps: { exclude: ['@saad5400/ai-kit'] },
ssr: { noExternal: ['@saad5400/ai-kit'] },   // Inertia SSR builds
```

## Upgrading to v0.8.0

v0.8.0 deletes the transitional propose → confirm → execute module — `Proposal`, `ProposalBag`, `ProposalExecutor`, `ProposalStatus`, `ProposalTrailer`, `ProposedWrite`, `Plan`, `PlanBuilder`, `CachePlanStore`, `WriteGate`, `WriteGateMode`, `ArrayActionRegistry`, the `PlanStore` / `ProposableAction` / `ActionRegistry` contracts, the exceptions only that flow threw (`ProposalNotPendingException`, `UnknownActionException`, `ActionValidationException`, `WriteRefusedException`) and `Testing\ProposalFactory`. It shipped in v0.3.0 while the classified-approvals decision was not visible ([`docs/DECISIONS.md`](docs/DECISIONS.md) #3) and was always flagged transitional; both consumers now run on `Approvals\Classified`.

**If your app is already on the classified seam, this release is a no-op.** Nothing in `Approvals\Classified`, the `WriteExecutions` ledger or `Approvals\Undo` changed.

Two config keys' worth of cleanup, and one database note:

- Drop `ai-kit.approvals.proposals_table`, `plan_cache_store`, `plan_ttl_seconds` and `auto_approve` from your published config — nothing reads them any more. `write_executions_table`, `undo` and `undo_table` stay. The `AI_KIT_PLAN_CACHE_STORE` / `AI_KIT_PLAN_TTL_SECONDS` env vars are dead.
- **Your `ai_proposals` table is left exactly where it is.** The kit simply stops shipping the migration that created it; it ships no drop migration and touches no data. An app that ran the old migration keeps the table (and its rows) until it chooses to drop it itself — do that on your own schedule, after confirming the rows are dead.

## Development

```bash
composer install
composer test   # pest
composer lint   # pint --test

npm install
npm test        # vitest — js/core + a compile check on the components
```

Tests run on Orchestra Testbench; no live AI calls in CI (recorded fixtures only). The JS suite runs under jsdom for the components and for the markdown tests that parse the sanitized output back to check what a browser makes of it; the renderer itself no longer needs a DOM.

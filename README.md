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
| `bench` | off | Scenario benchmark for tool-calling assistants: `TurnDriver` contract, `BenchRunner`, graders (+ optional LLM judge), pass@k JSON/Markdown report, `ai-kit:bench` — see [docs/BENCH.md](docs/BENCH.md) |

`Saad\AiKit\Testing` ships fakes + exported contract test suites for apps (dev-only, not a toggle).

## Shared model lanes

The kit carries the fleet's **model decisions** ([`docs/DECISIONS.md`](docs/DECISIONS.md) #28) — apps inherit them and no app names a slug of its own:

```php
'chat'      => ['model' => env('AI_KIT_CHAT_MODEL', 'deepseek/deepseek-v4-flash-0731')],
'vision'    => ['model' => 'google/gemini-3.1-flash-lite', 'fallback_model' => 'google/gemini-3.5-flash-lite'],
'documents' => ['model' => 'google/gemini-3.1-flash-lite', 'fallback_model' => 'google/gemini-3.5-flash-lite'],
'authoring' => ['model' => 'deepseek/deepseek-v4-pro-0813'],
```

| Lane | Read through | What routes here |
|---|---|---|
| `chat` | `Catalog::chatModel()` + `chatReasoningEffort()` | Every assistant turn. The floor of the app's chain — a user's catalog pick still wins. |
| `vision` | `Catalog::visionModel()` / `visionFallbackModel()` | The eyes-only pass: an image → text or structured JSON. |
| `documents` | `Catalog::documentsModel()` / `documentsFallbackModel()` | Anything the provider reads FROM A FILE: native scanned-PDF reads, ASR, whole-document summary/translation. Hard capability floor — must accept `file` and `audio`. |
| `authoring` | `Catalog::authoringModel()` | Admin-triggered, review-gated drafting. Rare, never on a student's latency budget. |

Every lane resolves to a model the shipped catalog also declares, so it inherits that entry's `fallbacks` and price cap for free; a test holds it there. An app key that ships EMPTY falls through to the kit — which is the intended shape, since the whole point of #28 is that a model changes here and reaches three apps through a version bump.

A default only moves when it is **same-or-cheaper AND same-or-faster AND same-or-smarter** — measured, not assumed. The `catalog.models` docblock carries the numbers behind the current pins.

## The model menu

`catalog.models` is the user-facing registry, ordered **cheapest first** (`sort_order` agrees with price, and a test says so). Two display rules are contract, not styling:

- **A model is shown by its `label`** — the provider's real name ("DeepSeek V4 Flash 0731", "Claude Sonnet 5"). Never an invented "{company} · {variant}" composition: `company` is the brand mark beside the name and `variant`/`tier` are grouping metadata, not names.
- **Cost is shown as a multiple of the default** — ×1, ×3, ×26 — never raw $/Mtok. The baseline is the default model, which is also the cheapest and carries both tags, so "×1" and "what you get if you choose nothing" are the same row.

Entries carry app-facing fields the kit never interprets (`key`, `company`, `variant`, `tier`, `effort`, `cache_read_usd_per_million`), preserved into `ModelDefinition::$extra`. `key` is stable identity: stored user selections reference it, so renaming one is a data migration.

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
| `error` | `{message, code?}` | **Terminal** — no `done` ever follows it. `code` is the kit's machine-readable reason (`stream_error`, `provider_unavailable`, `rate_limited`, `killed`, `stale`, `internal_error` — `Streaming\ErrorCode`, AG-UI's RUN_ERROR `code` role). Optional: a code-less frame is still valid, and a client treats an unknown code as a generic failure. |

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

A cancelled stream simply ends, so the fold takes its normal exit and the turn finishes on `done` with whatever it produced — a stop is a completed short turn, not an error. (`TurnBuffer::fail()` takes a fourth argument to append an empty `done` after `error`, for clients that hang their whole teardown off `done`. Off by default; the terminal contract above is what the kit promises.) This hand-rolled generator only stops the stream; `TurnRunner`'s own cancel generator also gets the stopped turn STORED — see below.

**Failed and stopped turns are stored** (owner ruling 2026-09-28: a failed turn shows in history as a failed message, with its partial text, for the app's retry button). laravel/ai 1.0 stores a dead run itself — RememberConversation's catch writes an assistant row with `status: failed`, `meta.error` and the steps it completed, every tool that already ran included — but only when the failure propagates through its generator. The kit makes sure it does, and tops up what it keeps:

- **A provider error inside the stream** (OpenRouter's `{"error":…}` frame, its usual mid-stream 502): the mapper emits `error` at once, then pulls the stream once more — 1.0 throws `StreamErrorException` on that pull, which runs the vendor's failure path. The expected throw is settled inside the fold (the wire already has its one terminal); walking away at the error, as before, left the vendor generator suspended so nothing was stored. An `on(Error)` hook that returns `true` meets the same throw on its next pull; the fold then ends failed with the default `error` frame, keeping the partial result.
- **A throw** (a 502 raised as an exception): unchanged on the wire; `TurnRunner`'s failed outcome now keeps the partial `StreamResult` (text, tool calls, results) instead of an empty one — pass your own result to `run()` / `runBuffered()` (`$into`) to get the same on the inline path.
- **A stop** (`TurnRunner`): 1.0 has no cancel, and an abandoned stream runs neither `then()` nor `catch()`. So the runner throws a `TurnCancelledException` into the stream where it stopped and catches it right back: the vendor stores the turn as a `failed` row with `meta.error === TurnCancelledException::MESSAGE` (`TurnState::of($status, $meta)` — or `TurnCancelledException::marks($meta)` — tells a stop from a failure), and the outcome is still `cancelled` + `done`. Behind a multi-provider failover list the throw lands in the outer failover stream, which carries no conversation hooks, so such a stop is not stored.
- **A step-guard wrap-up that errors** (the answer-now completion after a blank final step or step exhaustion, dying mid-stream): the whole step fails — the guard returns no step, stock throws on the next pull, and the turn is stored failed with the wrap-up's partial text. Before, the pre-wrap-up step was passed off as a success after the wire said `error` (its tool calls could even run) and nothing was stored. As defence in depth, a stream that keeps yielding past its settled error gets a `StreamErrorException` thrown into it (through `TurnRunner`'s stop generator too) rather than being walked away from.
- **A stop that lands too late** — after the run's final `StreamEnd` or its `ToolApprovalRequest` — is ignored: the turn is stored completed (with its usage row) or paused (the card the client already shows), never as a stopped `failed` row.
- **The circuit breaker** counts a step that ended on an in-stream provider error the kit reads as `provider_unavailable` / `rate_limited` as a failure; only a step that returned a response counts as a success (other in-stream errors move nothing). A mid-stream 502 storm now opens the breaker.
- **The conversation a failed first turn opened.** A completed turn's id reaches the app through the vendor's `then()`, which a failed or stopped turn never runs, so a client whose FIRST turn failed would start a duplicate thread on its next message or Retry. `TurnOutcome::$conversationId` (and `StreamResult::$conversationId`) names the conversation the turn was stored in — only once its row exists — and `TurnBuffer::fail(..., conversationId: $outcome->conversationId)` puts it on the frame as `error {message, code?, conversation_id}` and on the record meta as `conversation_id`; `runIntoBuffer()` does this itself. A stopped turn's outcome (`cancelled`) carries it too — put it in your `done`/finish meta. Clients adopt `conversation_id` from the error frame (`ErrorPayload.conversation_id?` in `js/core/events.ts`). On the inline `run()` path the `error` frame goes out before the vendor stores the turn, so read `$result->conversationId` after `run()` returns and send it in whatever frame your client reads last.
- **`InterruptedTurns`** (`streaming.remember_interrupted_turns`, default on) closes the two gaps stock leaves: the step that died mid-stream is added with the partial text the user already saw (no tool calls — none of them ran), and a turn that died in its FIRST step — for which stock stores nothing, not even the user's message — gets an empty step so the user row and a `failed` assistant row are written. Stock replays a failed row safely: a blank step as nothing, a partial one as the assistant text.

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
    $buffer->fail($turnId, $outcome->failure, $meta, code: $outcome->failureCode);   // the APP writes the terminal…
} else {
    $buffer->finish($turnId, $donePayload, $meta);         // …because its payload only exists after the fold
}
```

`TurnOutcome` carries the `StreamResult` (partial on a failure — never emptied by a throw), `cancelled` and `failed` as independent axes (a stop is a completed short turn with partial text, never an error), the resolved `failure` line and its `failureCode`, the `exception` when a throw ended the turn, and the mapper's assembled `done` payload for apps that build on it. What stays app-side, deliberately: model/user resolution, prompt assembly, metering, per-turn spend reset (catodemy's `TurnProviderSpend` is an app-level accumulator; the kit has no equivalent to reset), and — above all — the terminal event, because a completion payload (credit outcome, grounding, persisted message id) only exists app-side and only after the fold. `runIntoBuffer()` remains for apps that want the terminal written for them.

Three `streaming` config keys tune all of this — `page_size` (64), `stale_after_seconds` (300), `stale_trailing_done` (false) — and the provider wires them into the `TurnBuffer` it binds. The client half is `resumeTurn()`: see *Resuming a long turn* under the frontend layer.

## Turn robustness: a guaranteed final answer, and no leaked markup

Two failures showed up in real teacher threads. A turn that streamed «لنبحث أولاً عن مقرراتك.», called a tool, and then ended — the teacher replying «ما كملت جوابك». And a reply that contained the model's own tool-call grammar as text (`<｜DSML｜tool_calls>…`) because the OpenRouter upstream serving DeepSeek had not parsed it, so the teacher watched markup stream in and no tool ran. Both are handled in ONE place, the gateway's step guard, because the gateway is the only seam that sees laravel/ai's `StepContext`, the step's messages and its response together — and because whatever it adds is yielded INTO THE SAME STEP: the SDK loop sees one step, the persisted assistant message (`TextDelta::combine()` over the yielded events) carries the rescue without a phantom user turn, `StreamEnd` accumulates its usage, and every extra request is captured and charged like any other invocation (ruling #26c — cost stays provider-reported). Nothing above the gateway — mapper, runner, app job — changes.

**The last step never carries tools.** `gateway.final_step.withhold_tools` (default on) sends the final step of the budget without `tools` and with an answer-now nudge (`final_step.message`), so a turn cannot end on a tool call the loop would have discarded with its "maximum number of steps" sentinel. That makes the step budget read as *tool rounds + 1*: `ai-kit.chat.max_steps` (default 12) is the fleet's recommendation, and an agent defers to it with

```php
public function maxSteps(): int
{
    return \Saad\AiKit\Agents\StepBudget::default();   // laravel/ai resolves maxSteps() ahead of #[MaxSteps]
}
```

**The wrap-up.** `StepGuard` decides, per step, whether the turn still owes the user an answer — `blank_final`: a step that followed tool results (or a stripped markup leak) finished `stop` with empty text (DeepSeek does this after a tool-result step); `step_exhaustion`: the final step still ended in tool calls (only with withholding off). Either runs one tool-less completion on the same history plus the answer-now instruction and merges it into the step: text appended (a paragraph break when the step had narration), usage summed, the step's tool calls kept so the loop still settles their chips. A first-step blank reply with no tools involved is left alone — that is an ordinary empty answer for the app's guard, not a turn that went silent mid-work. Config lives under `ai-kit.chat.wrap_up`: `on_exhaustion`, `on_blank_final` (both default on), and `instruction` — null uses `gateway.final_step.message`, a literal string or a lang key overrides it, and `WrapUpInstruction::using(fn (string $reason): string => …)` is the closure seam for a per-locale line. Streamed and non-streamed steps get the same treatment.

**The markup leak.** `MarkupLeakFilter` is a streaming sanitizer inside `processTextStream` (and `parseTextResponse`): from the first configured marker to the end of the step, nothing reaches the client or the stored message. It holds a tail that could still become a marker (`<｜DS`) until the next delta resolves it and passes text without `<` through byte-for-byte, so a clean reply's deltas are untouched. When a step leaked but parsed no structured tool call, the guard escalates: **salvage** — an intact DSML `invoke` block naming an OFFERED tool becomes a real `ToolCall` (`DsmlToolCallParser`; ASCII-degraded `<|DSML|`/`<||DSML||` and orphan-invoke forms included) and the loop runs it as if the provider had parsed it; else **retry** the step once, excluding the upstream that leaked through OpenRouter's `provider.ignore` when the response named it (armed for exactly one body, so a long-lived gateway never carries it forward); else the stripped step falls through to the wrap-up. `ai-kit.gateway.markup_leak`: `enabled`, `salvage`, `retry`, `ignore_provider` (all default on), `patterns` (`MarkupLeakFilter::DEFAULT_PATTERNS` — DeepSeek's fullwidth and degraded markers plus the generic `<tool_call>` / `<function_calls>` / `<invoke name=` family). `gateway.require_parameters` (default on) additionally sends OpenRouter's `provider.require_parameters` on steps that carry tools, so only upstreams that support tool calling serve them — the cheapest way to not leak at all; it is merged with the catalog's `max_price`, and tool-less steps stay unrestricted.

**Counted, not guessed.** Every rescue stamps `TurnContext::flag()` — `wrap_up: blank_final|step_exhaustion`, `markup_leak`, `markup_salvaged`, `markup_retried` — and `RecordTurnUsage` writes them into the usage row's `context` JSON (a clean turn writes none), with a `warning` log line per leak and an `info` line per wrap-up. `ai-kit.chat.reasoning_on_tool_steps` (default true, today's behaviour) is a benchmark seam that drops the `reasoning` request field on steps following tool results, for measuring the DeepSeek card's "reasoning off for agentic turns" advice without touching the fleet's effort (ruling #26a).

**Narration is process, client-side.** `groupSegments()` now files text that is followed by a tool call anywhere later in the turn into the process disclosure — «لنبحث أولاً» was a step in the work, and as a reply bubble it made a silent turn read as the whole answer. Only text after the last tool call is the reply; cards still split groups; `ProcessGroup.items` may hold `TextSegment` (both components render it as a quiet narration line); the wire is unchanged and `groupSegments(segments, { narration: 'text' })` restores the old rendering.

## Stopped and failed turns: interrupted usage and spend

Owner ruling 2026-09-28: a turn the user **stops** is debited at the actual provider cost up to the stop; a **failed** turn stays free, but its cost counts toward the daily budget. laravel/ai 1.0 fires `AgentPrompted`/`AgentStreamed` only for completed or paused turns, and the step an interruption cuts off never receives the `usage.cost` OpenRouter sends on its final chunk — yet OpenRouter bills it. The usage module now closes both gaps with no app code involved.

**1. One `stopped` / `failed` row per interrupted turn.** `RecordInterruptedUsage` listens to laravel/ai's `AgentFailed` (every terminal failure, prompted or streamed) and to the kit's `Streaming\Events\TurnStopped`, which `TurnRunner` fires after settling a stop (laravel/ai reports no `AgentFailed` for a stop: the `TurnCancelledException` lands at the response's own iterator, outside its failure-reporting loop). It writes ONE `ai_usage_events` row — `status` `stopped` or `failed` (a free string column, so no migration), the exact cost + generation ids of every step the turn completed (plus an interrupted step whose usage frame arrived before the cut), the completed steps' tokens, `error` for a failure, `context.turn_id` and `context.pending_generation_ids` — and fires `TurnUsageRecorded($usage, 'stopped'|'failed')`; `$event->interrupted()`, `->stopped()`, `->failed()` read it. The budget listener counts both. It is idempotent: a run that already has a turn row — a completion that won the race against a stop, a replayed event — gets no second row. Toggle: `ai-kit.usage.record_interrupted` (true).

**2. The cut-off step, priced later.** The gateway marks every streamed generation *pending* on its first chunk (`SpendCollector::pendingGenerationIds()`) and retires it when the step completes, so a cut-off step is pending the moment it is cut — eagerly, never left to a generator's destruction. Whichever usage row is written next — the `stopped`/`failed` row, or a completed turn's row after a failover attempt or sub-agent was cut off on the way (`$event->turn->status` then `ok`/`paused`) — hands them to `InterruptedSpend`: the usage listeners are the single path, so no later AI call can drop them. It queues `ResolveInterruptedSpend`: one `GET /api/v1/generation?id=` look per id per attempt (`data.total_cost`), at `retry_delays_seconds` (5, 20, 60, 180, 300), then a warning naming the last HTTP status — never an exception. A zero cost while tokens are already counted reads as "not yet" until the last attempt. Each priced generation is budgeted at once via `BudgetGuard::recordOnce('interrupted:generation:{id}')`. When everything is priced (or on give-up, with what did price, if above zero) the kit writes a **delta row** — `status` `resolved`, same `invocation_id`, `cost_source` `generation_lookup`, `context.resolves` = the turn row's id — and fires **`Usage\Events\InterruptedSpendResolved`** once. A short lock covers the listener call and a "done" marker is set only after it returns: a worker killed in between (a deploy) is redelivered and fires again (the app's debit key dedups it), and a redelivery racing its original is released to retry.

Nothing in the pricing path throws into the turn: a queue that refuses the job settles with what priced and logs the rest. A configuration that can never price — no key, a `spend.provider` whose driver is not `openrouter` (its key is never sent to openrouter.ai), a 401/403 — is one error log, and nothing is queued. A `sync` queue connection (which ignores delays) is detected: nothing is queued and a warning says so — **a worker must drain `spend.connection` / `spend.queue`**. An inline first try exists (`sync_window_seconds`, default 0 — off: stats are almost never ready at the moment of a stop and it would hold the user's worker; never used for a failed turn; every request in it capped by the time left). So the late debit always lands after the turn's terminal frame: the `done` payload can carry the main debit, never this one.

**Why a dedicated event for the delta, not a second `TurnUsageRecorded`.** It arrives minutes later, in a queue job, outside the turn's job: every in-job accumulator the app keeps is gone, and the turn's main debit (`debit:turn:{id}`) may already have landed — a second `TurnUsageRecorded` for the same turn would collide with that key and be dropped silently, or be double-debited by a per-row listener, and the budget listener (keyed on the invocation id) would drop it too. So the delta is its own event with its own key: `$event->debitKey()` = `debit:turn:{turnId}:interrupted`, `$event->billable()` = not a failed turn, `$event->costUsd` = the delta only. `$event->turnId` / `$event->meta` are what the app passed to `TurnRunner::run(..., meta: [...])` (or `TurnContext::beginTurn()`), so the listener can find the payer. The kit's `CreditMeter::chargeResolved($payer, $event)` applies exactly that (no free-turn waiver — a stopped cheap answer is the abuse this closes).

**3. Collector hygiene.** `TurnRunner::run()` opens every turn with `TurnContext::beginTurn($turnId, $meta)`: a flushed `SpendCollector` plus the turn id and meta in hidden Context, forgotten in its `finally`. The queue worker's per-job Context reset does not cover a `dispatchSync()` / sync-driver job (it re-hydrates its caller's Context), an HTTP-inline turn, or a job that runs several turns — this does. Apps driving a turn without `TurnRunner` call `beginTurn()` / `endTurn()` themselves. Because Laravel serialises Context into every job dispatched mid-turn, the gateway's `Context::dehydrating()` hook also strips the spend lists, the pending ids and the turn id/meta from those payloads (the turn keeps its own), so a job can never count — or settle under the same turn id — spend that is not its own. With `usage.drain_spend` off (the app drains the collector itself) the flush is skipped, and interrupted rows carry no spend and price nothing rather than count what earlier rows already did.

**What apps delete, and what they listen to instead.**

- **catodemy**: delete `TurnProviderSpend::settleInterrupted()` and `forgetAll()` and their call sites (`GenerateAssistantReply`'s failed and stopped branches, `TelegramTurnFold`). A stopped turn meters `TurnProviderSpend::totalUsd()` exactly as a completed one does — the kit's `stopped` row already reached it through `TurnUsageRecorded`. A failed turn needs nothing: row and budget are the kit's. Keep `TurnProviderSpend::forget()` for the app's own ledger. Pass `meta: [...]` (user, course, model — whatever resolves the spend plan) to `TurnRunner::run()` and add one listener:

  ```php
  Event::listen(InterruptedSpendResolved::class, function (InterruptedSpendResolved $event) {
      if (! $event->billable()) {
          return; // failed turn: free, already on the budget
      }

      $credits = app(CreditCalculator::class)->creditsForCostUsd($event->costUsd);

      app(CreditDebitor::class)->debit($payerFrom($event->meta), $credits, [
          'turn_id' => $event->turnId, 'cost_usd' => $event->costUsd, 'generation_ids' => $event->generationIds,
      ], $event->debitKey()); // debit:turn:{turnId}:interrupted — unique-once
  });
  ```

- **s-grade**: delete `interruptedSpend()` and the `interrupted:` path of `recordUsage()` (its collector read and its `BudgetGuard::recordOnce()`): an interrupted turn now HAS a kit `UsageEvent` row, so `recordUsage()` reads its cost from the row like any other turn, and the budget is already recorded. Look the row up with `whereIn('status', ['ok', 'paused', 'stopped', 'failed'])` (or the lowest id): the delta row shares the invocation id. Add an `InterruptedSpendResolved` listener that writes the app ledger row and charges under `$event->debitKey()` when `billable()`.

`ResolveInterruptedSpend` needs a real queue worker on `spend.connection` / `spend.queue`; under the sync driver its delays collapse to nothing. Config (`ai-kit.spend`): `resolve_interrupted` (true; false skips the pricing — no HTTP, no job), `provider`, `record_budget`, `retry_delays_seconds`, `sync_window_seconds`, `sync_backoff_ms`, `request_timeout_seconds`, `connection`, `queue`, `cache_store` (the once-guard; null = the safety store). A nested agent's failure writes its own `failed` row and drains the collector, exactly as a nested completion already does — isolate nested agent calls (catodemy's `BackgroundAi::isolate()`), as for completions.

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

**Check ownership before you resume.** Since laravel/ai 1.0, `storeApprovalResults()` finds the paused turn by conversation id ALONE — it no longer scopes the lookup to the participant, and neither does the kit's store. Whoever reaches `continue($conversationId, …)->prompt($decisions)` runs the paused tool. Authorize first, with the store's own check:

```php
use Laravel\Ai\Models\Conversation;

abort_unless(app(ConversationStore::class)->conversationBelongsTo(
    $conversationId, Conversation::participantType($user), Conversation::participantKey($user),
), 404);
```

(`ConversationOwnership::owns()` is a deprecated alias of this for one release.) A resume whose decisions name no pending call throws `ApprovalMismatchException` **and fails the paused turn in place** (`status = failed`, stock 1.0 behaviour, kept on purpose). Guarding stale and double-tapped decisions is the app's job, BEFORE the agent runs: build them with `ResumeDecisions::fromClient($input, $cards->editGuard($pending))` from the server's `StoredApprovals::pending()` set — the edit guard throws on an id that is not pending, so a second tap or a stale card never reaches the agent and never fails the pause.

Resuming on a queue? A closure cannot travel in a job payload, so guard in the request and dispatch the plain result — `ResumeDecisions::guarded($input, $cards->editGuard($pending))` returns the same client-shaped decisions with every edit reconciled, having round-tripped them through `fromClient()` so an unreadable shape throws in the request rather than in the job. The job then resumes with a bare `fromClient($guarded)`.

### A card without a form

Not every surface can render a form. `ApprovalCards::text($approval, $locale)` returns the same card as plain text — one fact per line: the title, the tool's human label (dropped when it only repeats the title), a warning line when the call is destructive, `• Label: value` per visible argument, the tool's preview lines, then the reason:

```
إنشاء فصل «المصفوفات»
الأداة: Upsert Chapter
• المسار: الأساسيات
• مجاني: لا
السبب: يُنشئ محتوى في مقرر منشور.
```

It is the same server-derived payload `card()` builds, so a text surface inherits the same guarantee — the classification, the title and the preview come from the tool instance, never from the model. Hidden fields, readonly identity fields (`track_id`), arguments the model left out and a preview line that only repeats the title are all left out, mirroring what the web card does with them; values are flattened to one line and capped at 160 characters. An `AskUser` pause renders as the question and its suggested answers.

**Plain text means plain text**: no HTML, no Markdown, no escaping. The transport that adds markup escapes it — Telegram's HTML parse mode included. `$locale` switches the whole rendering (the kit's copy and the app copy the tool resolves) and switches back, so a queue worker running under `en` can still render an Arabic card.

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

import { resizable } from '@saad5400/ai-kit/svelte/resizable'      // sidebar resize (Svelte action)
import { useResizable } from '@saad5400/ai-kit/vue/resizable'      // …or Vue composable

import '@saad5400/ai-kit/styles/prose.css'                        // optional
import '@saad5400/ai-kit/styles/resizable.css'                    // optional
```

Contents: `events` (the table above, as TypeScript), `sse` (a reader for POST-response streams — `EventSource` cannot send a body — wrapping `eventsource-parser` for the framing and keeping the fetch/abort shell, a JSON-with-raw-fallback parse, a `maxBufferSize` cap, and one deliberate spec departure: a final frame the server never closed with a blank line is still dispatched), `timeline` (the ordered segment reducer, below), `resume` (the resumable-turn reader for the buffered path, below), `fields` (the form-schema presentation helpers the two component sets share — label humanizing, the identity-field split, machine-value detection), `cards` (the card-level ones: `previewLines()`, which drops a preview row that only repeats the title), `resizable` (the framework-free sidebar resize helper, below), `markdown` (unified + GFM, sanitized on the hast tree by `rehype-sanitize` so no DOM is needed, raw HTML escaped to literal text, every link `target="_blank" rel="noopener noreferrer nofollow"`, plus a throttled `createLiveRenderer` that runs `remend` over the partial buffer so a half-written `**bold` never flashes its asterisks), and `vue/` + `svelte/` components (`Markdown`, `ProcessGroup`, `ApprovalCard`, `ApprovalFields`, `QuestionCard`, `ToolChip`).

Theming is CSS variables only — set them once on a container and every component follows:

| Token | Default | Used for |
|---|---|---|
| `--ai-kit-accent` / `--ai-kit-accent-fg` | `#3b82f6` / `#fff` | confirm button, focus ring, answered marker |
| `--ai-kit-destructive` / `--ai-kit-destructive-fg` | `#ef4444` / `#fff` | destructive card border/tint/confirm, failed chip |
| `--ai-kit-muted` | `color-mix(currentColor 65%, transparent)` | labels, reasons, thinking text |
| `--ai-kit-border` | `color-mix(currentColor 22%, transparent)` | card borders, rules, disclosure rails |
| `--ai-kit-control-border` | `color-mix(currentColor 30%, transparent)` | option chips, inputs, the badge and the reject button — deliberately NOT `--ai-kit-border`, so a hairline divider token cannot make a tappable chip look like text |
| `--ai-kit-surface` | `transparent` + a static `currentColor 5%` tint | card and disclosure backgrounds |
| `--ai-kit-badge-bg` | `color-mix(currentColor 10%, transparent)` | the status badge's pill |
| `--ai-kit-hover` | `color-mix(currentColor 8–10%, transparent)` | chip / skip / disclosure hover |
| `--ai-kit-radius` | `0.5rem` | corners |
| `--ai-kit-code-font` / `--ai-kit-code-size` | mono stack / `0.8125rem` | machine names, ids, code and markdown editors |
| `--ai-kit-progress` | `var(--ai-kit-accent)` | the tool chip's determinate progress bar |
| `--ai-kit-handle-size` / `--ai-kit-handle-hover` | `0.25rem` / `color-mix(currentColor 22%, transparent)` | the sidebar resize handle |

The neutral defaults are mixed out of `currentColor` rather than hardcoded greys, so the components read correctly on a **dark** admin panel with no app CSS at all — mapping the tokens to your design system is refinement, not a prerequisite.

**Map them to COLORS, not to channel triplets.** `--ai-kit-accent: var(--primary)` is right when `--primary` is `oklch(…)` or `#…`; it is a silent catastrophe when the app stores raw channels for `hsl()` to consume (`--primary: 240 6% 10%`, the shadcn-v3 convention) — the substituted value is not a color, the declaration is invalid at computed-value time, and the fill or border simply does not paint. Use `hsl(var(--primary))` in that case. Since v0.9.0 the components keep every token color out of the `border` / `outline` shorthands, so a mistake here degrades to a `currentColor` border instead of erasing the border, the badge and the button fill at once — but the mapping is still yours to get right.

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

`ApprovalCard` is the whole card: header with an `icon` slot, the title (once — see below), a status badge (`لا يمكن التراجع` / `قابل للتراجع`), the reason, preview lines, the form, and the confirm/reject row with confirm first in reading order so RTL puts it on the right. A destructive card takes the destructive accent on its border and confirm button, derived from the same server flag as the behaviour. Its `decide` event hands you exactly what `ResumeDecisions::fromClient()` accepts — `{action: 'approve'}`, `{action: 'edit', arguments}` or `{action: 'reject'}` — so the handler is one request. There is no separate "edit" button: the form **is** the edit affordance.

`ApprovalFields` renders the field schema on its own if you want your own chrome: hidden skipped, readonly as a definition row (never a disabled input), the rest as their matching control, long text as an auto-growing editor that scrolls internally past ~40vh with a character count, and `markdown`/`code` in mono — each replaceable per widget through the `field` slot (Vue) or snippet (Svelte). A value that looks like a machine token (an id, an enum member, a path) renders mono, `dir="ltr"` and bidi-**isolated**, which is what stops `action: create` from rendering as a scrambled "create action:" inside an Arabic card.

`QuestionCard` takes an optional `answer` (or `skipped`) and settles into a record of what was actually answered rather than a bare "answered" label — pass it from your persisted thread and a reloaded page renders its history the same way.

### The v0.9.0 card pass

The cards were redesigned from prod screenshots ([`docs/DECISIONS.md`](docs/DECISIONS.md) #22 — per-app **theming** stays, card structure and behaviour are the kit's). What changed, and what it means for a consumer:

- **Bidi everywhere.** Every text node that can carry mixed direction — question, option, title, reason, preview line, field label, field value, badge, button copy — is a `<bdi dir="auto">`. That is what stops `الشابتر"Web"` and `العابدية والزاهر48` from gluing the wrong way round. Layout is logical-only (`margin-inline`, `border-inline-start`, `text-align: start`); a test in `js/components.test.ts` fails the build if a physical `left`/`right` creeps back in.
- **Options are chips.** Bordered, rounded, hoverable, focus-visible, keyboard-activatable buttons that wrap — and the tapped one keeps an `aria-pressed` "chosen" state while the decision is in flight, so choosing reads as a choice.
- **One title.** `previewLines()` drops a preview row that only repeats the title (whitespace- and case-insensitively), which is what rendered it twice in prod. A `{key: value}` preview map now renders as humanized `Label: value` rows too.
- **Two tiers of field.** Readonly identity fields (`id`, `*_id`, `*_uuid`) collapse into a `<details>` disclosure under `detailsLabel` instead of leading the card with `track_id: v6oPvGqX`. Everything else stays a headline row in declared order.
- **Real labels.** A tool's own `Field` label reaches the card unchanged — **declare them**, in the conversation's language, and this is the fix worth making app-side. An unlabelled argument is humanized (`track_id` → `Track`) rather than printed as a snake_case token.
- **No bare dashes.** An absent value renders `emptyLabel`.
- **Prominence.** A pending card carries an accent rail on its inline-start edge and a static `currentColor` tint over whatever `--ai-kit-surface` resolves to, so it separates from the page and from the process disclosure next to it. Pass `pending={false}` for a historical card. `groupSegments()` guarantees the card is a **top-level** group — render it as a sibling of the steps disclosure, never inside it, or the app buries the card again.
- **Copy is props, all of it.** `confirmLabel`, `rejectLabel`, `destructiveLabel`, `undoableLabel`, `pendingLabel` (overrides the non-destructive badge), `detailsLabel`, `emptyLabel` on the approval side; `placeholder`, `sendLabel`, `skipLabel`, `answeredLabel`, `skippedLabel`, `pendingLabel` on the question side. Defaults are Arabic (the fleet is Arabic-first, and both consumers were relying on those defaults) — pass your own through `t()`.

### Resizable sidebar

Every app that hosts the assistant in a sidebar makes it user-resizable on desktop, width remembered per browser ([`docs/DECISIONS.md`](docs/DECISIONS.md) #23). `createResizable()` is the framework-free helper; the Svelte action and the Vue composable are thin wrappers over it.

```svelte
<aside class="assistant" bind:this={panel}>
    {#if panel}
        <div class="ai-kit-resize-handle" use:resizable={{ panel, storageKey: 'catodemy.assistant.width' }}></div>
    {/if}
    …
</aside>
```

```ts
const { handle, panel, width } = useResizable({ storageKey: 'uqucc.assistant.width', min: 320, max: 640 })
// <aside ref="panel"> <div ref="handle" class="ai-kit-resize-handle" /> …
```

- **RTL-aware.** The drag is logical: the handle sits on the panel's inline-**start** edge (for the default `dock: 'inline-end'`), and dragging toward the inline start widens it. The physical sign is read from the panel's own direction per drag, so the same sidebar grows the right way in Arabic and English. `dock: 'inline-start'` flips it for a panel docked the other way.
- **Desktop only.** Below `media` (default `(min-width: 1024px)`) the helper attaches nothing and applies nothing, so a stacked phone layout never inherits a 520px width; it watches the query, so crossing the breakpoint turns resizing on and off without a remount.
- **Persistence** is one `localStorage` write per drag (on release, not per frame), under your `storageKey`, clamped to `min`/`max` on read. Storage that throws (sandboxed iframes, full quota) is caught, not fatal; pass `storage: null` to keep a drag ephemeral.
- **Keyboard and a11y.** The handle is marked `role="separator"`, focusable, with `aria-valuemin/max/now`; ArrowLeft/ArrowRight move it by `step` in the same logical direction as the drag.
- **`apply(width, panel)`** overrides how the width lands (default: an inline `width` in px) for a layout driven by a CSS variable or a grid track; `width()`, `resize()`, `reset()` and `destroy()` are the rest of the surface.
- `styles/resizable.css` is optional, but `touch-action: none` on the handle and the `user-select` kill under `.ai-kit-resizing` are load-bearing — copy them if you style your own.

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

## Upgrading to laravel/ai 1.0 (conversation store)

laravel/ai 1.0 stores a turn as `steps` (one entry per round trip, each tool result on its call) plus a `status` (`completed` / `paused` / `failed`) instead of `tool_calls` / `tool_results` / `approval_state`. The kit's `EncryptedConversationStore` now writes that schema, sealed: `content`, `attachments`, `steps` and `meta` are ciphertext at rest (`usage` and `status` stay plaintext). A resumed pause folds into the row it paused on; a run that throws is stored as a `failed` turn with `meta.error`.

**The migration.** `move_agent_conversation_messages_onto_steps` ships with the kit and runs with your normal `php artisan migrate` (pgsql and sqlite). It adds `steps` / `status`, makes `tool_calls` / `tool_results` nullable, rebuilds `participant_index` with `agent`, and converts every existing row — decrypting the 0.10 columns and sealing `steps` / `meta` the way the bound store writes them (and always sealed when the source row was ciphertext: an app that turned encryption off never has once-encrypted data decrypted at rest by a migration; such a row's `content` is ciphertext to the vendor store anyway). Unlike upstream's backfill it **keeps pending approvals**: a call still pending becomes a `paused` row that `pendingApprovalsFor()` returns and a resume completes. Memory stays bounded on long threads (rows convert one at a time).

- **What it writes.** `steps`, `status`, and `meta` (rewritten without `reasoning` / `provider_content_blocks` / `provider_steps`, which move into steps or are dropped). `tool_calls`, `tool_results` and `approval_state` are never written, so a worker still on the 0.10 code keeps reading them mid-deploy.
- **What a rollback to the 0.10 code degrades.** Rows converted here lose `meta.reasoning` and the paused turn's raw provider blocks to the old reader (a paused turn replays through the generic path). Rows WRITTEN by 1.0 carry nothing in the 0.10 columns: the old code sees their text only — no tool calls or results — cannot see or resume a 1.0 pause (`approval_state` is NULL), and shows a failed turn as a normal reply.
- **Undecryptable rows are left alone.** A row whose ciphertext this app key cannot decrypt (the key rotated without `APP_PREVIOUS_KEYS`) keeps `steps` NULL and every other column exactly as it was; so does every unconverted row of a conversation whose tool results cannot be decrypted. The migration logs their ids (and prints them when run from a console) and still succeeds; the command below prints them and exits non-zero. Restore the key and re-run it.
- **The deploy window.** A worker still on the 0.10 code writes rows with `steps` NULL, and may answer a converted pause in the legacy columns. The encrypted store heals a conversation on first read (history, `pendingApprovalsFor()`, a resume) — converting those rows and folding the late results onto the pause — and the command does the same in bulk:

```bash
php artisan ai-kit:backfill-conversation-steps   # idempotent; run once the deploy settles
```

- **Phase B** (a later release) drops the 0.10 columns and makes `steps` NOT NULL. It MUST refuse to run while any row still has `steps` NULL — those are exactly the rows above that could not be converted, and dropping the columns would lose them for good.

**App changes.**

- Reading message rows directly: `tool_calls` / `tool_results` / `approval_state` are frozen legacy data now. Read `steps` through `ConversationContent::revealJson($row->steps)` (the vendor `ConversationMessage` model's `array` casts cannot read ciphertext — they yield null), `content` through `ConversationContent::reveal()`, and `status` plain. Or use the store: `paginateConversationMessages()` hands out decrypted `StoredMessage`s (`toolCalls()`, `toolResults()`, `steps`, `status`).
- A pending call is a stored call with `approval_reason` and no `result` (`PendingApproval::isPending()`). `StoredApprovals::pending()` is now a wrapper over the store's `pendingApprovalsFor()`: only the NEWEST turn's pause counts (a pause the user walked away from is settled as denied by the next run). Its `$connection` argument is ignored.
- Transcripts: expect ONE assistant row per turn, including turns that paused and resumed, and `failed` rows (filter on `status` if you hide them).
- Rendering how a turn ended: `Saad\AiKit\Conversations\TurnState::of($row->status, $row->meta)` returns `TurnState::Completed` / `Paused` / `Failed` / `Stopped` (string values `completed` / `paused` / `failed` / `stopped`). 1.0 stores a turn the user stopped as `failed`; `Stopped` is that row, recognised by `meta.error === TurnCancelledException::MESSAGE` — show it as stopped, not as an error with a retry button. `$meta` can be the decoded array or the raw column (sealed or plaintext, as the store wrote it — traces off still keeps the error); `TurnState::ofMessage($storedMessage)` classifies what `paginateConversationMessages()` hands out, and `->interrupted()` is true for `Failed` and `Stopped`. Under the encrypted store a failed or stopped row is sealed like any other (`content` / `steps` / `meta` ciphertext, `meta.error` included), so read `meta` through the helper or `ConversationContent::revealJson()`, never with a SQL JSON operator.
- `ConversationOwnership` is deprecated — use `conversationBelongsTo()` and see "Check ownership before you resume" above.
- With `persist_tool_traces` off, an assistant row keeps a content-only step (1.0 replays assistant text from `steps`); meta keeps only a failed turn's `error`.
- `ai-kit:prune-conversations` strips traces out of the sealed `steps` row by row (keeping the text), and also empties the legacy columns. A row whose traces live only in `steps` (meta and attachments `'[]'`) is stripped too; one that is already content-only is re-read but never rewritten. It skips only a `paused` row that is still its conversation's newest assistant row (the one pause 1.0 can resume); an abandoned pause is stripped like any other row. A row with `steps` still NULL keeps it NULL for the backfill, and a row whose `steps` does not decrypt is left untouched and counted in a warning. A failed turn keeps its sealed `meta.error`, so a stopped turn still reads as `TurnState::Stopped` after stripping.

## Upgrading to v0.9.0

Additive: nothing was removed, no prop or event changed shape, and every new prop has a default. A v0.8.0 consumer compiles and renders untouched. Three things are worth doing on the bump:

- **Declare `Field` labels on your classified tools.** The card humanizes an unlabelled `track_id` to `Track`, which is better than a snake_case token and still not Arabic. A label is one argument: `Field::make('name', label: 'اسم الشابتر')`.
- **Pass the new copy props** — `detailsLabel`, `emptyLabel`, and `pendingLabel` if you want the badge to say "awaiting your approval" rather than "undoable" — through your own translator. The defaults are Arabic, so an Arabic app is not wrong out of the box, just generic.
- **Check your `--ai-kit-*` mapping is made of colors.** See the theming note above: a token mapped to raw HSL channels never painted, and that is most of what the redesign was fixing.

Also new: `ai-kit.chat.model` (the shared default chat model, `Catalog::chatModel()`), the resizable sidebar helper, and `js/core/cards.ts`. `fieldLabel()`'s `machine` flag is deprecated — labels are always human copy now, so it is always `false`; `displayValue()` takes the empty-value placeholder as a second argument.

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

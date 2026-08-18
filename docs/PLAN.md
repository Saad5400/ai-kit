# ai-kit — program plan

> Rebuilt 2026-08-17 from a four-way survey (kit audit + AI-surface maps of uqucc-laravel,
> catodemy, s-grade). This is the working plan the maintainers drive from; the README
> describes the finished product.
>
> **Owner decisions live in `docs/DECISIONS.md` and outrank this file.** The original
> record was not visible when this plan was rebuilt, and several owner decisions were
> unknowingly reversed; the 2026-08-17 audit reconciled them (approvals, encryption,
> retention, s-grade sequencing — see the deviation ledger there).

## Program decisions (locked)

- **Distribution**: GitHub VCS (`Saad5400/ai-kit`) with tags; apps add a local composer
  `path` repository override during active migration. CI/prod resolve from GitHub.
- **UX vs UI**: the kit unifies the *UX contract* (payload shapes, event names, status
  machines). UI stays per-app (uqucc = Inertia/Vue, catodemy + s-grade = Inertia/Svelte).
- **Order** (owner ruling 2026-08-17, DECISIONS.md #15): finish the kit milestone an app
  needs *before* migrating that app. Pilot = **uqucc-laravel** → **catodemy next** →
  **s-grade LAST** as one combined jump (0.7.2→0.10 + adoption + wallet migration), in a
  school-holiday window, only after ≥2 weeks clean catodemy prod. Parallel *branch prep*
  for s-grade is fine; its merge/deploy waits for the window and the gate.
- **Delivery**: feature branch + GitHub PR per app; merges allowed once tests pass.
- **Approvals design** (owner ruling 2026-08-17, DECISIONS.md #3): the contract is
  laravel/ai 0.10 native **`Approvable`** with classified pause — the owner reverted this
  plan's propose→confirm→execute standardization. The shipped v0.3.0 approvals module is
  **transitional**: it stays in prod until the Approvable rework ships (see M5), and the
  rework must preserve its real wins (server-derived `destructive`, idempotent execution
  ledger, preview == execution).

## State (2026-08-18)

Real modules: **gateway**, **catalog** (config + DB sources, `ai-kit:sync-models`,
task routing), **usage**, **safety** (wired live in uqucc via TurnGuard/KillSwitch),
**conversations** (encrypted store, ownership guard, pruning, `reveal()` read seam),
**streaming**, **approvals** (transitional per DECISIONS.md #3, flagged as such in
config + provider) + **undo** (ledger, CompensationApplier, UndoTurn),
**attachments**, **agents** (MCP adapters — `laravel/mcp` is a live dependency),
**credits**. Stub: rag. `Saad\AiKit\Testing` ships fakes + the Proposal factory.
Suite: 300 tests green.

2026-08-18 alignment pass (after the DECISIONS.md reconcile): kit defaults now match
the rulings — `conversations.encrypt => true` (#8, per-app opt-out) and
`retention_days => null` = forever (#9; the prune command warn-no-ops without a
window). uqucc already ships its own overrides (encrypt on, 90d).

2026-08-18 (owner ruling, same day): the **Approvable rework was pulled forward**
ahead of v0.4.0 — `Approvals\Classified` (Effect + Capability, ClassifiedTool on
native `Approvable`, ApprovalCards, ResumeDecisions, AskUser) plus its prerequisite,
**encrypted tool traces** (store encrypts attachments/tool_calls/tool_results/meta/
approval_state; `trace_retention_days` window in the prune command; traces default
ON). The proposal machinery is now transitional for uqucc only; catodemy migrates
straight onto the classified seam.

2026-08-18 (later the same day): **v0.4.0 tagged and pushed** (M4 + the pulled-forward
rework), then **v0.4.1** (`Classified\StoredApprovals` — the read seam a client needs
to repaint pending cards after a reload). **uqucc migrated onto the classified seam:
PR #129 squash-merged** (uqucc `522ab91`, pins `^0.4.1`) — writes pause natively,
decisions resume the turn via `POST …/chat/{conversation}/decide`, the proposal
endpoints/adapters are deleted (prod `ai_proposals` verified empty, no bridge needed),
AskUser is live, and encrypted traces turn on with the bump. uqucc suite 1168 green
(the 2 homepage failures pre-exist). **Not yet deployed** — Coolify deploy is manual;
`php artisan migrate` on deploy adds `ai_write_executions`. Once deployed and stable,
the kit's transitional proposal module has no consumers left and can be retired in M5.

**v0.5.0 — streaming defaults + frontend layer** (owner rulings DECISIONS.md #18/#19,
2026-08-18), on `feat/streaming-and-ui`:

- **Streaming UX parity is now a kit default.** `StreamEventMapper` collected
  `ToolCall`/`ToolResult` into the result and dropped `ReasoningDelta` unless an app
  hooked it, so each app re-built the thinking block and the tool chips s-grade already
  had. It now emits `reasoning {text}` and `tool {id, name, status, successful?}` by
  default. Reasoning is bracket-free on the wire (no start/end events — the client
  closes its block on the first following `delta`/`tool`/terminal), so a replayed
  buffer needs no bracket repair. Tool arguments and results deliberately stay
  server-side: these apps are public-facing and tool payloads carry retrieved records.
  Both channels emit at the point they occur, ahead of text a transformer is holding.
  `on()` hooks still replace the default for their event class;
  `withoutReasoning()` / `withoutToolEvents()` opt out. The buffered path needed no
  change — the new events flow through `append()`.
- **The kit ships a frontend layer**, same repo, same tag: root `package.json`
  (`@saad5400/ai-kit`), installed as `npm install github:Saad5400/ai-kit#semver:^0.5.0`.
  Source TypeScript under `js/`, no build step — the consuming app's Vite compiles it.
  `js/core/events.ts` (the wire contract as types), `js/core/sse.ts` (a dependency-free
  reader for POST-response streams; `EventSource` cannot send a body, which is why all
  three apps hand-rolled one), `js/core/markdown.ts` (unified + GFM → DOMPurify, ported
  from s-grade's pipeline: raw HTML escaped to literal text rather than dropped, every
  link `target="_blank" rel="noopener noreferrer nofollow"`, render failure degrading to
  escaped pre-wrap, and a throttled `createLiveRenderer` for streaming), and thin
  `js/vue/` + `js/svelte/` mirrors of `Markdown` / `ThinkingDisclosure` / `ToolChip`.
  uqucc's hand-rolled markdown parser is retired by this.
  This narrows the "UI stays per-app" decision above rather than reversing it: the
  *plumbing* is shared, the look and feel is not — components carry only structural CSS
  behind `--ai-kit-*` variable hooks, and `js/styles/prose.css` is opt-in.
- The markdown port was reconciled against s-grade's actual `lib/carta.ts` +
  `Markdown.svelte` after the fact (kit ships plain unified rather than Carta, and no
  math/highlighting — but the sanitizer hook now guards on `href`, the live renderer
  paints the first push immediately before throttling at 250ms/20k chars, the components
  take s-grade's `onLinkClick` delegated-anchor prop for in-app routing, and
  `prose.css` carries over the logical-property RTL prose rules from `.sg-prose`).
- Suite: 336 PHP tests green, 47 vitest tests green (jsdom, not happy-dom — happy-dom's
  `NodeIterator` makes DOMPurify strip the root element of every fragment).

Tags: v0.1.0 … v0.3.2, **v0.4.0, v0.4.1**.

## What the surveys established (the shared core)

Each app hand-built the same infrastructure. Reference implementations, by concern:

| Concern | uqucc | catodemy | s-grade |
|---|---|---|---|
| Gateway | (migrated to kit ✅) | `app/Ai/Gateway/ReasoningOpenRouterGateway.php` (0.9) | same path (0.7) |
| Conversation store | vendor store + string-participant migration | `app/Ai/Conversations/EncryptedConversationStore.php` | `app/Ai/Support/EncryptedConversationStore.php` |
| Streaming | inline SSE `emit()` ×2 (byte-identical) | `AssistantTurnBuffer` + `AiStreamController` (resumable, cursor) | `AssistantTurnBuffer` + `ChatController` (resumable) |
| Approvals | `AdminPendingAction` + `ProposalExtractor`/`Executor` (per-action confirm) | `WriteGate`/`ProposalBag`/`PlanStore`/`ActionRunner` (plan card, approve = new turn w/ `plan_id`) | `WriteGate`/`PlanDraft`/`ProposePlan` (plan card, typed_confirm, undo ledger) |
| Spend | `SpendLedger` + `ai_usage` | `AssistantCreditMeter` + wallets (`debit:turn:{id}`) | `AssistantCreditMeter` + `assistant_usage_events` |
| Kill switch / budget | `AiSettings` toggles + `daily_budget_usd` | none (credits.enforce only) | none |
| Attachments | text-layer-vs-vision router, 20k cap | sync doc extract + vision-in-job | Vision/Document/Local extractors, hash cache |
| Embeddings/RAG | `Embedder`/`TextEmbedder`/`OpenRouterEmbedder`/`FakeEmbedder` + RRF hybrid retriever | same pattern + `hnsw.iterative_scan` fix | none |
| Catalog | operator-editable models in `AiSettings` | `ai_models` table + `ai:sync-models` + `provider_max_price` | 11-model config registry |
| MCP adapters | `AssistantActionTool` + 2 servers | `McpToolAdapter` + Read/Write split | Read/Write/Destructive adapters + OAuth server |

Canonical wire contract observed (fetch + ReadableStream, never EventSource; frames
`event: NAME\ndata: {json}\n\n`): `delta|text-delta {text}`, `error {message}`,
terminal `done {conversation_id, …}`, plus extension events (`proposal`, `citations`,
`plan`, `question`, `step`, `segment`). Pre-flight failures are plain JSON at
503/429/422/402, discriminated client-side by Content-Type.

Hard constraints the kit must never break:
- `Promptable` static faking on app-defined agent classes (`Agent::fake()`,
  `assertPrompted`, `preventStrayPrompts`) — 119 call sites in uqucc alone.
- laravel/ai 0.10 gateway layering: recursion lives in `TextGenerationLoop`;
  `processTextStream()` handles a single step (4-arg signature).
- uqucc test pins: the gateway's 2-arg constructor and `buildStepBody`'s 9-arg
  signature (its `AiGatewayRegistrationTest` + `ReasoningGatewayFinalStepTest`).
- String participants: account-less apps need string `participant_id` (vendor
  migration types it bigint).

## Milestones

### ✅ M1 (v0.1.x) — scaffold + gateway + safety primitives
### ✅ M2 (v0.2.0) — catalog (config), usage metering, circuit breaker + fallback chains, turn traces, drift guard
### ✅ M3 shipped 2026-08-17 as v0.3.0 (243 tests; review-hardened pre-release)

v0.3.1 (same day): the approvals executor's card-facing error strings moved to
`ai-kit::approvals` lang lines (en + ar).

**✅ uqucc migration COMPLETE — PR #127 squash-merged to main 2026-08-17**
(uqucc pins ^0.3.1; suite 1167 passed, the 2 homepage-404 failures pre-exist):
- ✅ Stage A: SpendLedger deleted → usage module + BudgetGuard via
  `App\Ai\KitSafetySettings` (AiSettings adapter); `ai_usage` history imported
  into `ai_usage_events` then dropped; PageCopilot unmetered-spend gap closed.
- ✅ Stage B: `ConversationOwnership` for the five ownership guards (now checks
  participant type AND id); schedule runs `ai-kit:prune-conversations --days=7`;
  `App\Listeners\PruneChatAttachments` cascades attachments off
  ConversationsPruning; app prune command deleted.
- ✅ Stage C: both controllers fold through StreamEventMapper into SseStream
  (link guard as TextTransformer, citations via beforeDone, proposal cards via
  ToolResult hook); duplicated `emit()`s deleted. Telegram's fold deliberately
  stays app-side (raw events → throttled progress editor, not the SSE contract).
- ✅ Stage D: kit approvals — `AdminPendingAction` + app executor/extractor
  replaced by kit `Proposal`/`ProposalExecutor`/`ProposalTrailer`; the app's
  unified `AdminActionRegistry` backs the kit `ActionRegistry` via the lazy
  `ProposableAdminAction` adapter (actions still execute as the proposing
  admin); data migration kept ULIDs so stored trailer ids resolve.

**Audit follow-up shipped — PR #128 squash-merged 2026-08-17** (the two gaps
the post-migration goal audit flagged): `config/ai-kit.php` published + pinned
(retention_days 7, schedule drops `--days`), and the safety seam is live —
turn entries (chat send, attachment upload, admin-assistant send) gate through
`TurnGuard::check(scope)`; probes (chat show, admin index/show/confirm/reject,
search, tool traits, Telegram silent gates) consult `KillSwitch::engaged(scope)`.
Arabic messages byte-identical; the kit's cache kill switch now actually stops
every surface. Background pipeline (copilot/authoring/extractors/ingest)
deliberately stays on AiSettings. `AuthorPageFromDocumentAction` now
constructor-injects PageAuthor + BudgetGuard. Suite 1171 passed (+4 tests).

Migration learnings for catodemy/s-grade: keep old-table create migrations,
import with a fresh-marker (`cost_source: imported`) or preserved ULIDs where
ids travel through stored content; test-create conversations must set
`participant_type` to the owner class; kit Proposal has no factory — create
rows directly in tests; publish + pin `config/ai-kit.php` in the SAME PR as
the migration and wire TurnGuard/KillSwitch at the gates from the start.

### M3 (v0.3.0) — what uqucc needs to finish its migration

1. **safety-wiring** — feed `BudgetGuard::record()` from `TurnUsageRecorded`; a turn-entry
   guard composing `KillSwitch::enforce()` + `BudgetGuard::enforce()` (+ optional
   concurrency); a `SafetySettings` contract so apps back toggles/budget with their own
   settings store (uqucc: Spatie `AiSettings`). Small, unlocks everything.
2. **conversations** — `EncryptedConversationStore` on the 0.10 `ConversationStore`
   interface (encrypt content, `'[]'` extras, plaintext-tolerant decrypt); publishable
   **string-participant** migration; `ConversationOwnership::owns()` (fixing the
   participant_type omission all five uqucc guards share); `ai-kit:prune-conversations`
   with a pruning event so apps cascade-delete attachments.
3. **streaming** — `SseStream` emitter (headers, emit, keepalive, test short-circuit);
   `StreamEventMapper` folding laravel/ai stream events into the canonical wire events
   with app hooks; `TurnBuffer` (cache-backed, sequence-numbered, cursor tail,
   separate cancel key, `claimPersist()`) for the resumable path.
4. **approvals** — the unification centerpiece. Contracts: `ProposableAction`
   (validate/execute/summary/category/destructive), `ActionRegistry`. `Proposal`
   model + publishable migration (ulid, type, category, payload, summary, status
   pending|confirmed|rejected|failed, proposed_by string, error, executed_at) with
   `toClientPayload()` = uqucc's 7-field flat shape. `Plan` value object + builder
   (superset of catodemy/s-grade shapes: id, summary, steps[{id,type,title,preview,
   destructive,typed_confirm?}], scope, destructive, auto_approve, status) with
   `destructive` always server-derived. `WriteGate` (immediate/propose/execute modes,
   runtime-resolved only — never prompt-visible; scope guard; execute-only-approved).
   `ProposalExecutor` (transaction, re-resolve, re-validate against current state,
   409 semantics on non-pending). Idempotency ledger `ai_write_executions
   (turn_id, sequence)` unique, inserted in the same transaction as the write.
   Undo = contract + `undoable` flag only in M3 (s-grade's ledger informs M4).

Release: tagged v0.3.0 + v0.3.1. ✅ uqucc migration PR #127 merged (see status above).

### M4 (v0.4.0) — what catodemy + s-grade additionally need

**Code complete on main 2026-08-17 (all six items below); awaiting the v0.4.0 tag.**

- **credits** — `CreditCalculator`, meter base with `debit:turn:{id}` idempotency,
  free-turn waiver policy, 402 gate contract (wallet *policy* stays per-app).
- **catalog completion** — DB-backed `CatalogSource`, `ai:sync-models`,
  `provider_max_price` plumbing, recommended-per-task invariant.
- **attachments** — extraction router (born-digital text layer ≥ threshold → free parse,
  else vision), sha-256 cache, extract-on-upload job base, extraction-usage recording
  with derived turn ids.
- **agents (MCP)** — `AiToolAdapter` base (snake name, schema forwarding),
  Read/Write/Destructive adapters with interface-parameterized scoping; makes
  `laravel/mcp` a live dependency.
- **Testing namespace** — `src/Testing/` (autoloaded in the main PSR-4 map since
  `/tests` is export-ignored): fake `SpendCollector`, array `CatalogSource`,
  in-memory `TurnBuffer`/`ProposalStore`, contract test suites for app adapters.
- **undo** — `UndoLedger` + `UndoTurn` from s-grade's `ActionRunner`/
  `CompensationPlanner` shape.

Release: tag v0.4.0 — which now also carries the pulled-forward classified
approvals seam and encrypted tool traces (owner ruling 2026-08-18). **Then:
catodemy migration PR — its plan flow lands on `Approvals\Classified`, NOT the
transitional proposal machinery; s-grade branch may be prepped in
parallel but merges only per the DECISIONS.md #15 gate** (school-holiday window,
≥2 weeks clean catodemy prod).
s-grade extras: 0.7→0.10 jump (gateway Context reads → SpendCollector; conversation
store interface + participant data migration; the 14-arg reflection test dies).
catodemy extras: rewrite the five raw-HTTP OpenRouter callers onto kit plumbing.

### M5 (v0.5.0) — uqucc rework adoption + opt-in long tail

The owner-ruled rework itself was **pulled forward and shipped 2026-08-18**
(Saad's ruling; see the State section): ✅ approvals on `Approvable`
(`Approvals\Classified` — classified pause, editable `Decision::edit` forms,
one-click destructive cards, idempotent execution, preview == execution),
✅ AskUser on the same pause seam (DECISIONS.md #6), ✅ encrypted tool traces +
separate trace-retention window (DECISIONS.md #7). ✅ **uqucc migrated off
`Proposal`/`WriteGate`** onto the classified seam (PR #129, 2026-08-18 — traces
enabled with the bump; deploy pending). Remaining:

- **retire the transitional proposal module** from the kit once uqucc's #129 deploy
  is out and stable — it has no consumers after that.
- **resumable turns in uqucc** — adopt the kit `TurnBuffer` path (queue-worker
  generation, replay-from-last-id + tail) per DECISIONS.md #17; today uqucc still
  generates inside the HTTP request.

Opt-in long tail:

- **rag** — `Embedder`/`OpenRouterEmbedder`/`FakeEmbedder`, chunker, hybrid retriever
  (pgvector cosine + keyword leg, RRF k=60, `hnsw.iterative_scan`, graceful vector-leg
  skip off pgsql). Sources: uqucc `CorpusRetriever` + catodemy's iterative-scan fix.
- Rate-limit trio helper (named burst limiter + per-owner daily quota with
  end-of-day decay + notify-once-per-window).
- Anything the migrations surfaced.

## Migration checklists (apps)

**uqucc** ✅ DONE (PR #127, merged 2026-08-17): pin bump, SpendLedger→usage,
AiSettings→SafetySettings adapter, SSE dedup, approvals adoption, ownership
helper, prune command, two defect fixes.

**catodemy** (~150 files touched, ~60 absorbed): gateway subclass deleted for kit's;
TurnBuffer/SSE/reader contract from kit; EncryptedConversationStore from kit;
WriteGate/ProposalBag/PlanStore/ActionRunner from kit (keeps `ActionExecutor`);
catalog schema + sync from kit (11 rows stay); credit ledger core from kit
(SpendResolver policy stays); raw-HTTP callers rewritten; laravel/ai ^0.9 → ~0.10.

**s-grade** (~3k lines absorbed of ~17k): gateway swap + 5 Context reads →
SpendCollector; EncryptedConversationStore + **production data migration**
(user_id → participant_type/participant_id); TurnBuffer from kit; metering/catalog/
extraction from kit; plan flow onto kit approvals (keeps ActionType enum + executor +
compensation specifics); laravel/ai 0.7.2 → ~0.10 + mcp 0.8 → 0.9 (adapters unaffected);
delete dead `ChatMessage.proposals` scaffolding; rewrite the reflection test.

## Working agreements

- Module = own service provider, own config section under `ai-kit.*`, publishable
  migrations tagged `ai-kit-migrations`, Pest feature tests, pint clean.
- Nothing domain-specific enters the kit: no prompts, no app model names, no Arabic
  copy except where it is the mechanism (gateway "answer now" nudge, safety lang files).
- Every extraction preserves the app's test seam (`Agent::fake()` etc.).
- CI matrix (Laravel 12/13 × prefer-lowest/prefer-stable) must stay green; drift-guard
  pins vendor internals we override.

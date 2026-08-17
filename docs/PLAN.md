# ai-kit — program plan

> Rebuilt 2026-08-17 from a four-way survey (kit audit + AI-surface maps of uqucc-laravel,
> catodemy, s-grade). Replaces the lost `AI-KIT-PLAN.md`. This is the working plan the
> maintainers drive from; the README describes the finished product.

## Program decisions (locked)

- **Distribution**: GitHub VCS (`Saad5400/ai-kit`) with tags; apps add a local composer
  `path` repository override during active migration. CI/prod resolve from GitHub.
- **UX vs UI**: the kit unifies the *UX contract* (payload shapes, event names, status
  machines). UI stays per-app (uqucc = Inertia/Vue, catodemy + s-grade = Inertia/Svelte).
- **Order**: finish the kit milestone an app needs *before* migrating that app.
  Pilot = **uqucc-laravel** (already pins v0.1.1 and uses the kit gateway), then
  catodemy + s-grade in parallel.
- **Delivery**: feature branch + GitHub PR per app; merges allowed once tests pass.
- **Approvals design**: the kit standardizes the **propose → confirm → execute** pattern
  the three apps independently built, NOT laravel/ai's `Approvable`. Rationale: zero of
  the three apps use `Approvable`; the apps' pattern is richer (server-derived
  `destructive`, typed confirmation, scope guard, idempotent execution ledger, undo seam)
  and executes *stored* proposals rather than re-driving the model, which makes
  "what executes == what was previewed" literally true (catodemy learned this the hard way).
  `Approvable` remains a possible substrate later; it is not the contract.

## State (2026-08-17)

Real modules: **gateway** (canonical `ReasoningOpenRouterGateway`, retries, circuit
breaker, drift-guard), **catalog** (config source + fallback chains; DB source and
`ai:sync-models` missing), **usage** (events, TurnSpend, TTFT traces — best covered),
**safety** (KillSwitch/BudgetGuard/TurnConcurrencyLimiter written and tested but
**wired to nothing**). Stubs: agents, conversations, streaming, approvals, attachments,
rag, credits. `Saad\AiKit\Testing` does not exist. `laravel/mcp ~0.9.0` is currently a
dead dependency (kept for the agents module). Suite: 90 tests green.

Tags: v0.1.0, v0.1.1 (uqucc's current pin), v0.2.0 (M2, current main).

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

**uqucc migration status (branch `feat/ai-kit-v0.3`, draft PR #127):**
- ✅ Kit pin v0.1.1 → v0.3.0; suite at parity with main (1166 passed; the 2
  homepage-404 failures pre-exist on main).
- ✅ Stage A: SpendLedger deleted → usage module + BudgetGuard via
  `App\Ai\KitSafetySettings` (AiSettings adapter); `ai_usage` history imported
  into `ai_usage_events` then dropped; PageCopilot unmetered-spend gap closed.
- ⏳ Stage B: `ConversationOwnership` for the five ownership guards (adds the
  missing participant_type filter), kit prune command + ConversationsPruning
  listener for ChatAttachment cascade, drop the app's own prune command.
- ⏳ Stage C: SseStream + StreamEventMapper to dedupe the two byte-identical
  controller `emit()`s (and Telegram's fold).
- ⏳ Stage D: admin proposal flow onto the kit approvals module
  (`AdminPendingAction` → kit `Proposal`; needs a data migration — the kit
  table adds `category`, proposed_by becomes a string owner key).

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

Release: tag v0.3.0. **Then: uqucc migration PR** (catch pin up from v0.1.1; replace
`SpendLedger` with usage module + budget wiring; adopt SseStream + mapper; move
`AdminPendingAction` machinery onto approvals; adopt ownership helper + prune; fix the
two flagged defects — PageCopilot unmetered spend, participant_type in ownership checks).

### M4 (v0.4.0) — what catodemy + s-grade additionally need

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

Release: tag v0.4.0. **Then: catodemy + s-grade migration PRs in parallel.**
s-grade extras: 0.7→0.10 jump (gateway Context reads → SpendCollector; conversation
store interface + participant data migration; the 14-arg reflection test dies).
catodemy extras: rewrite the five raw-HTTP OpenRouter callers onto kit plumbing.

### M5 (v0.5.0) — opt-in long tail

- **rag** — `Embedder`/`OpenRouterEmbedder`/`FakeEmbedder`, chunker, hybrid retriever
  (pgvector cosine + keyword leg, RRF k=60, `hnsw.iterative_scan`, graceful vector-leg
  skip off pgsql). Sources: uqucc `CorpusRetriever` + catodemy's iterative-scan fix.
- Rate-limit trio helper (named burst limiter + per-owner daily quota with
  end-of-day decay + notify-once-per-window).
- Anything the migrations surfaced.

## Migration checklists (apps)

**uqucc** (~1 PR): pin bump, SpendLedger→usage, AiSettings→SafetySettings adapter,
SSE dedup, approvals adoption, ownership helper, prune command, two defect fixes.

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

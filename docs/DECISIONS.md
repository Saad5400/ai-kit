# ai-kit — Owner Decision Record

> **This file is the authority on program decisions.** `docs/PLAN.md` is the working
> plan built ON these decisions; when the two disagree, this file wins. Read both before
> touching any AI-layer work in the kit or the apps.
>
> **Process rule:** entries here change only when Saad explicitly rules — in his own
> words or through an answered question. If an implementation needs to diverge, ship it
> flagged as *transitional* and add the divergence to the ledger below; never silently
> ratify a reversal. This file exists because the 2026-08-17 plan rebuild ran without
> access to the original decision record (it lived outside the repo) and unknowingly
> reversed several owner decisions; keeping the record in-repo prevents a repeat.

## Decisions in force

Original decisions Saad made 2026-08-12 (interactive design session), amended by his
2026-08-17 rulings after the deviation audit. ⚠ = diverged from consultant
recommendation at decision time.

1. **Package**: `saad/ai-kit`, namespace `Saad\AiKit`, one modular package, public
   GitHub repo (`Saad5400/ai-kit`), sole requirer of laravel/ai + laravel/mcp, PHP ^8.4.
2. **Stay on laravel/ai + laravel/mcp** — Neuron AI rejected (no MCP server component,
   breaking beta, bus-factor-1).
3. **Approvals — RE-AFFIRMED 2026-08-17**: classified pause on laravel/ai 0.10 native
   **`Approvable`**. Safe+undoable writes execute immediately (logged/undoable);
   destructive/irreversible pause the turn. The v0.3.0 approvals module
   (Plan/WriteGate/Proposal/executor, stored-proposal execution) shipped while this
   decision was not visible and is **transitional**: it stays in prod (uqucc runs it)
   until apps migrate off it. The rework must
   preserve what the transitional module got right: server-derived `destructive`,
   idempotent execution, "what executes == what was previewed".
   **Ruled 2026-08-18 (Saad): the Approvable rework is pulled FORWARD — built before
   tagging v0.4.0, so catodemy never adopts the transitional machinery.** Shipped
   same day as `Approvals\Classified` (Capability/Effect, ClassifiedTool,
   ApprovalCards, ResumeDecisions, AskUser), preserving all three wins.
   *Status note (appended 2026-08-20, no ruling changed): with uqucc (#129) and
   catodemy (#558) both live on the classified seam, the transitional module had
   no consumers left and was **retired from the kit in v0.8.0** — the classified
   pause is now the only approval seam the kit ships.*
4. **Confirm UI**: editable form for payload writes (prefilled from tool schema,
   same validated path as human UI, audit flags `edited_by_user`); one-click cards for
   destructive. ⚠ Typed-name confirm tier dropped — undo ledger is the net.
5. **Undo**: kit ships contract + turn-actions ledger + UndoTurn runner; s-grade plugs
   its CompensationPlanner; apps without undo get a narrower auto-execute tier.
6. **AskUser**: unified pause/resume mechanics on the same paused-turn contract as
   approvals; answers resume mid-turn. (Missing from the rebuilt plan — restored as a
   planned milestone item.)
7. ⚠ **Tool traces — RE-AFFIRMED 2026-08-17**: persisted **encrypted**, short retention
   (7–30 days, separate from conversation retention). Was OFF everywhere because
   the store only encrypted `content`. **Kit work item shipped 2026-08-18** (with the
   pulled-forward Approvable rework, which needs stored traces to resume):
   EncryptedConversationStore now encrypts attachments / tool calls / tool results /
   meta / the pause marker (usage stays plaintext), and
   `ai-kit:prune-conversations` strips traces past `trace_retention_days`
   (kit default 14). Kit defaults now: `persist_tool_traces => true`. Apps may
   enable on their next kit bump.
8. **Conversation encryption — RE-AFFIRMED 2026-08-17**: **ON by default** everywhere,
   per-app opt-out. uqucc flips `conversations.encrypt => true` (plaintext-tolerant
   reads make this safe; one-way for rows written while on).
9. **Retention — RE-AFFIRMED 2026-08-17**: forever (s-grade, catodemy), **~90 days**
   for uqucc's anonymous threads (the shipped 7-day window is reverted to 90).
10. **BudgetGuard** daily-USD kill switch: all three apps. (Wired live in uqucc via
    TurnGuard/KillSwitch, PR #128 — fulfills the "central kill switch in the dispatch
    pipeline" decision.)
11. **Catalog**: `CatalogSource` interface — config+DB+`ai-kit:sync-models` for
    catodemy/s-grade, config-only for uqucc.
12. **Upstream PRs to laravel/ai deferred**; the drift-guard test detects when upstream
    absorbs our fixes.
13. **Upgrade pace**: lag by default, never >2 minors behind, weekly canary CI.
14. ⚠ **Schemas converge at adoption** — one-time data migrations per app to canonical
    shapes (not config-mapped).
15. **Rollout order — RE-AFFIRMED 2026-08-17**: uqucc pilots every module in prod
    first → **catodemy next** → **s-grade LAST**, as ONE combined project
    (laravel/ai 0.7.2→0.10 + full adoption + CreditBalance→wallet migration), in a
    **school-holiday window**, only after **≥2 weeks clean prod** on catodemy.
    The rebuilt plan's "catodemy + s-grade in parallel" is overruled; parallel *branch
    prep* is fine, s-grade's merge/deploy waits for the window and the gate.
16. **Credits**: opt-in module, shared mechanics only (generalized wallet/transaction
    models, CreditCalculator, idempotent `debit:turn:{id}` meter base, free-turn
    waiver); wallet policy, pricing, checkout, refunds stay per-app. uqucc never
    enables it.
17. **Streaming robustness** (2026-08-12 SSE review): SSE confirmed, WebSockets
    rejected. Target architecture: generation decoupled from the HTTP request
    (queue-worker default), durable buffer with monotonic ids, replay-from-last-id +
    tail. The kit's `TurnBuffer` implements the buffer; uqucc currently still generates
    in-request — adopting the resumable path there is planned work, not abandoned.
18. **Streaming UX parity is a kit default — RULED 2026-08-18** (after the uqucc
    pilot showed reasoning + tool-call streaming missing while s-grade had both):
    `StreamEventMapper` emits `reasoning` deltas and `tool` running/done status
    events **by default** (safe payloads — no arguments/results on the wire;
    opt-outs per app). An app must get s-grade-grade streaming by adopting the
    kit, not by re-implementing it.
19. **The kit ships a frontend layer — RULED 2026-08-18**: same repo, same tag,
    npm-installable (`github:Saad5400/ai-kit`), shipping the wire-contract types,
    the SSE reader, a correct sanitized markdown pipeline (uqucc's hand-rolled
    parser is retired), and thin Vue + Svelte components (markdown, thinking
    disclosure, tool chips). UI look & feel stays per-app; the plumbing does not.
20. **Chat model is under review — RULED 2026-08-18**: `deepseek/deepseek-v4-flash`
    is too slow (no "low" reasoning effort, high effort crawls). A faster
    same-price-class replacement with reliable tool calling is being selected;
    model ids stay per-app config, never hard-coded in the kit.
    *Superseded in part by #21 (2026-08-20): the kit now carries the shared
    DEFAULT model id; per-app overrides remain config.*
21. **Shared default chat model — RULED 2026-08-20 (Saad)** *(SUPERSEDED by #26 on
    2026-08-24: the shared default is now `deepseek/deepseek-v4-flash`, Gemini Flash Lite
    is demoted to the vision-only slot, and the uqucc DB override this entry tolerated is
    deleted.)*: the kit ships a
    shared default chat model in its config; apps inherit it unless they
    explicitly override. All three apps default to **Google Gemini Flash
    Lite** — slug pinned `google/gemini-3.5-flash-lite` (latest lite
    generation at ruling time; tools + reasoning + structured outputs +
    multimodal, 1M context; $0.30/$2.50 per M). Cheaper prior generations
    (`3.1-flash-lite`, `2.5-flash-lite`) are the fallback candidates if cost
    or behavior disappoints. uqucc caveat: its `AiSettings->chat_model` DB row
    overrides config and must be migrated at adoption. s-grade inherits at its
    migration (#15).
22. **AI cards are first-class UI — RULED 2026-08-20 (Saad, prod screenshots)**:
    the question and approval cards shipped in v0.6.0 are not good enough
    ("very bad and meh"). Kit components get a real design pass: proper option
    chips and buttons (not text runs), full RTL/bidi correctness (Latin
    fragments inside Arabic copy must isolate), no raw field names / internal
    ids / bare-dash empties in the primary view, and a visual hierarchy that
    makes a pending card unmistakable. App-side card copy (tool `title()` /
    `preview()`) must be localizable — an English title rendered to an Arabic
    user is a defect, not a default. Refines #19's "look & feel stays per-app":
    per-app THEMING stays, but card structure/behavior is kit-owned.
23. **Resizable AI sidebar + readability — RULED 2026-08-20 (Saad)**: every
    app that hosts the assistant in a sidebar makes that sidebar user-resizable
    on desktop (width persisted per user/browser); the kit ships the shared
    resize helper for both frameworks. Catodemy additionally scales the
    assistant font up slightly on desktop — it is hard to read.

24. **Long turns are ordinary turns — RULED 2026-08-20 (Saad)**: long AI work
    runs INSIDE the chat turn, and the turn stays open until it ends — a
    separate task entity, job, card or endpoint was explicitly rejected.
    Anything the AI does may take a long time (a summary, a slide-deck
    translation, a 40-item classification); that is GENERAL, not a special
    case, so robustness belongs to the kit's general turn machinery, never to
    per-feature plumbing. For anything beyond the turn's own SSE tail (the
    180 s hangup + cursor reconnect stays the contract), polling beats a held
    connection. The chat UI is the only surface — progress rides the existing
    `tool` event on the tool chip; no new surfaces. Shipped as the "long
    turns" work (kit PRs #9–#11; tags as v0.10.0 after the #22/#23 v0.9.0
    release): TurnBuffer header/pages split + heartbeat + stale-tail terminal
    + `upsert()`, mapper coalescing + `ToolProgress` + `TurnRunner`, and the
    `tool.progress` wire field + chip progress bar + `resume.ts` client-side.

25. **Chat attachments are durable history — RULED 2026-08-23 (Saad)**:
    (a) catodemy's attachment retention prunes the BYTES only (7-day window
    unchanged, disk protected); the row survives with a `pruned_at` stamp and
    the chat renders a disabled "expired" chip with real copy — a thread must
    never silently lose a file it visibly carried. (b) uqucc gets the same
    sent-message attachment treatment catodemy shipped in its #578:
    message-level attribution, attachments in the thread contract, an
    owner-only download route (404 posture), client rendering live +
    rehydrated. (c) uqucc's layout stays as-is — full pages + copilot
    dialogs, NO sidebar conversion; ruling #23's resizable sidebar applies
    only to apps that already host the assistant in a sidebar.

26. **Shared chat + vision models, and provider-only cost — RULED 2026-08-24 (Saad)**,
    superseding #21 and closing #20. Trigger: uqucc had been answering real students on
    Gemini Flash Lite, which Saad judged "just too stupid for real students"; a config
    hotfix to DeepSeek appeared to land and changed nothing, because uqucc's
    `AiSettings->chat_model` database row silently beat config.
    (a) **Chat**: the fleet default is **`deepseek/deepseek-v4-flash`** at
    `reasoning_effort: medium` ("deepseek mid reasoning"), shipped in the kit's
    `ai-kit.chat.*`. #20's objection was re-measured on the current build and no longer
    holds — low/medium/high all answer in 4–6 s, so effort is a quality dial, not a
    latency one. Apps override through `AI_KIT_CHAT_MODEL` or their own published config
    and **through nothing else**: the uqucc `AiSettings` model rows (`chat_model`,
    `vision_model`, `embedding_model`) and their `/manage` fields are DELETED, because a
    layer that can silently beat config is how this incident happened.
    (b) **Vision**: a SEPARATE shared default, `ai-kit.vision.model` =
    **`google/gemini-2.5-flash-lite`**, pinned on cost — the cheapest vision-capable
    model that still does tools + structured outputs ($0.10/$0.40 per M, 2.5× cheaper
    than the `google/gemini-3.1-flash-lite` it replaces, which stays its fallback). The
    split is forced, not stylistic: the chat default is **text-only** on OpenRouter, so
    an image routed at it fails outright. Gemini is for eyes, DeepSeek is for talking.
    (c) **Cost is whatever OpenRouter says it is.** Hand-maintained per-million price
    tables are DISPLAY METADATA ONLY and may never price a turn: the estimate fallback is
    gone from `RecordTurnUsage` and `CreditMeter`, an unreported cost records NULL and
    waives rather than guessing, and `ModelDefinition::estimatedCostUsd()` is renamed
    `displayCostEstimateUsd()` so the billing path cannot re-acquire it by accident.
    (d) **The catalog is shared too**: the kit ships the fleet's model registry in
    `ai-kit.catalog.models` (workhorses, vision tier, and the premium user-selectable
    tier catodemy/s-grade expose). Because kit config deep-merges, apps inherit it and
    may add to it by key; an app that must own the whole menu sets
    `catalog.replace_shipped_models`. Future model changes happen HERE and reach the apps
    through a version bump, never by editing three configs.

## Deviation ledger

| Shipped (v0.3.x / PR #127–#128) | Owner ruling 2026-08-17 | Resolution |
|---|---|---|
| Approvals on kit Plan/WriteGate, `Approvable` rejected | **Revert** | ✅ Rework SHIPPED 2026-08-18 (pulled forward by Saad's 2026-08-18 ruling, ahead of v0.4.0); transitional module stays only until uqucc migrates off proposals; ✅ retired from the kit in v0.8.0 (2026-08-20) |
| Conversation encryption opt-in, uqucc plaintext | **Turn ON in uqucc** | `conversations.encrypt => true` in uqucc config (shipped with this record) |
| uqucc retention 7 days | **Restore ~90d** | `retention_days => 90` in uqucc config (shipped with this record) |
| Tool traces never persisted | **Restore encrypted 7–30d** | ✅ Kit side shipped 2026-08-18: traces encrypted + `trace_retention_days` window; apps enable on next kit bump |
| catodemy + s-grade migrations in parallel | **Restore s-grade rules** | s-grade last, combined jump, holiday window, ≥2 weeks clean catodemy prod |
| M2 dual-write reconciliation week skipped | Accepted (history imported as `cost_source: imported`) | One-time reconciliation of `ai_usage_events` vs the OpenRouter dashboard instead |
| M1 clean-week soak gate ignored | Superseded by rollout rule #15 | Gates live at app-migration boundaries (clean-prod window before the next app) |
| AskUser absent from milestones | Restore | Re-added to PLAN.md alongside the approvals rework |

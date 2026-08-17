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
   until the Approvable-based rework ships, then apps migrate off it. The rework must
   preserve what the transitional module got right: server-derived `destructive`,
   idempotent execution, "what executes == what was previewed".
4. **Confirm UI**: editable form for payload writes (prefilled from tool schema,
   same validated path as human UI, audit flags `edited_by_user`); one-click cards for
   destructive. ⚠ Typed-name confirm tier dropped — undo ledger is the net.
5. **Undo**: kit ships contract + turn-actions ledger + UndoTurn runner; s-grade plugs
   its CompensationPlanner; apps without undo get a narrower auto-execute tier.
6. **AskUser**: unified pause/resume mechanics on the same paused-turn contract as
   approvals; answers resume mid-turn. (Missing from the rebuilt plan — restored as a
   planned milestone item.)
7. ⚠ **Tool traces — RE-AFFIRMED 2026-08-17**: persisted **encrypted**, short retention
   (7–30 days, separate from conversation retention). Currently OFF everywhere because
   the store only encrypts `content` — enabling `persist_tool_traces` today would store
   traces in plaintext. Kit work item: encrypt traces + a separate trace-retention
   window; only then flip it on in apps.
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

## Deviation ledger

| Shipped (v0.3.x / PR #127–#128) | Owner ruling 2026-08-17 | Resolution |
|---|---|---|
| Approvals on kit Plan/WriteGate, `Approvable` rejected | **Revert** | Module is transitional; rework on `Approvable` scheduled (PLAN.md), then migrate uqucc off proposals |
| Conversation encryption opt-in, uqucc plaintext | **Turn ON in uqucc** | `conversations.encrypt => true` in uqucc config (shipped with this record) |
| uqucc retention 7 days | **Restore ~90d** | `retention_days => 90` in uqucc config (shipped with this record) |
| Tool traces never persisted | **Restore encrypted 7–30d** | Blocked on kit trace-encryption + separate window; work item added, then enable |
| catodemy + s-grade migrations in parallel | **Restore s-grade rules** | s-grade last, combined jump, holiday window, ≥2 weeks clean catodemy prod |
| M2 dual-write reconciliation week skipped | Accepted (history imported as `cost_source: imported`) | One-time reconciliation of `ai_usage_events` vs the OpenRouter dashboard instead |
| M1 clean-week soak gate ignored | Superseded by rollout rule #15 | Gates live at app-migration boundaries (clean-prod window before the next app) |
| AskUser absent from milestones | Restore | Re-added to PLAN.md alongside the approvals rework |

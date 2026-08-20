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
  plan's propose→confirm→execute standardization. The shipped v0.3.0 approvals module was
  **transitional**: it stayed in prod until the Approvable rework shipped (see M5), and the
  rework had to preserve its real wins (server-derived `destructive`, idempotent execution
  ledger, preview == execution). ✅ **Retired from the kit in v0.8.0** once both consumers
  ran on `Approvals\Classified`; only that seam ships now.

## State (2026-08-20, later) — long turns merged (#9–#11; shipped inside v0.9.0)

The long-running-turns slice (owner rulings recorded as DECISIONS.md #24: the
work stays inside the open chat turn, robustness is general turn machinery,
polling over held connections, the chat UI is the only surface) is merged to
main as three PRs:

- **#9 TurnBuffer** (`2e4ccb9`): the record splits into a header +
  `page_size` pages (default 64), so `append()` is O(1) in the turn's length
  (was a whole-record read-modify-write per delta — O(n²) over a 10-minute
  turn); `heartbeat_at` stamped on every write + `touch()` between them; the
  tail fails a running turn whose heartbeat is older than
  `stale_after_seconds` (300) with a localized terminal (`staleMessage`
  argument, `ai-kit::streaming.stale` en/ar, `stale_trailing_done` for
  done-teardown clients) behind an atomic claim; `upsert()` keeps
  progress-style events to one log entry; `status()`/`exists()` header-only
  reads; pre-split inline records still read back.
- **#10 frontend** (`50ec98e`): `ToolPayload.progress {label?, percent?,
  current?, total?}` + the timeline's merge rules (an absent `name` keeps the
  held one, `progress` replaces wholesale, dropped on `done`); `ToolChip`
  renders the label / `12/40` (LTR-isolated) / a determinate bar on
  `--ai-kit-progress`; `ProcessGroup` passes progress through and its live
  summary line shows the last running chip's label; `js/core/resume.ts`
  (`./resume` export) — catodemy's cursor / backoff-ladder / 404 / silence
  reader, extracted.
- **#11 mapper + runner** (`b912da2`): `coalesce()` / `withoutCoalescing()`
  with the new `runBuffered()` — coalescing defaults ON on the buffered fold
  (every frame is a cache write replayed to every resuming client) and OFF
  inline (uqucc's per-token feel untouched); `ToolProgress` /
  `ToolProgressReporter` (static per-turn seam, 1/s throttle that still
  touches the heartbeat, `each()` stops iterating on cancel — no exception);
  `TurnRunner::run(...): TurnOutcome` — kill-switch re-check, feature
  Context label, acting-user swap, cancel generator, append/upsert sink; the
  app keeps metering, prompt building, per-turn spend reset and writes the
  terminal finish/fail itself from the outcome.

Suite: **420 PHP** green (was 349), **116 vitest** (was 92). **No version
bump or tag yet** — sequencing agreed 2026-08-20 with the v0.9.0
cards/sidebar/default-model work (rulings #21–#23, the ai-kit PM session):
they tag v0.9.0 first — and since #9–#12 merged ahead of #13, **v0.9.0 carries the whole long-turns slice; the separate v0.10.0 was dropped** (agreed with the PM session 2026-08-20).

Follow-ups: the catodemy adoption slice (branch `claude/slice-long-turns`;
spec at catodemy `docs/design/slices/slice-long-turns-plan.md` — TurnRunner
under `GenerateAssistantReply`, a dedicated `assistant` queue + Horizon
supervisor, first progress consumers `ClassifyResources` + `GenerateMcq`,
client onto `resumeTurn()`); uqucc swaps the app-side reconnect ladder it
shipped in its PR #136 onto `js/core/resume.ts` at its next kit bump
(tracked on its backlog); s-grade unchanged per DECISIONS.md #15.

## State (2026-08-19)

**catodemy migration COMPLETE — four PRs merged to main and auto-deployed
2026-08-19** (Coolify deploys catodemy main on merge; each deploy verified live):

- **#550 platform** (`5550ccbb`): kit ^0.7.0 underneath — kit gateway (app
  subclass deleted), kit EncryptedConversationStore + the 0.10 participant
  rename/backfill on the live tables (morph alias `user`, 50 conversations;
  migration batch 66), encrypted tool traces (trace_retention_days 14,
  conversations kept forever per #9), app-side `AiModelCatalogSource` over the
  existing `ai_models` (the kit DB source's canonical schema didn't fit — first
  DB-backed consumer lesson), `ai_usage_events` + `TurnProviderSpend` (sums only
  provider-sourced costs per turn; vision folds in, estimates never masquerade),
  TurnGuard at the turn entry + queued-job kill-switch re-check + budget with a
  cache override. Billing (`AssistantCreditMeter`/`SpendResolver`, idempotency
  keys) byte-identical.
- **#554 streaming** (`7ce2f45f`): app TurnBuffer/SSE controller deleted for the
  kit's; wire renamed to the canonical contract (`delta {text}`, `reasoning
  {text}`, tool `running/done`, snake_case done); client on kit `readSseStream`
  (frame ids = resume cursor) + `createTimeline`; Carta stays for bubble
  markdown per #19. Mid-stream provider `error` is now terminal, generic-copy,
  unmetered (cancelled turns still meter).
- **#558 approvals** (`2a14d26b`): plan flow
  (WriteGate/ProposalBag/PlanStore/ActionRunner) DELETED; 13 write tools extend
  app `WriteTool` on `Approvals\Classified` (pause per call, editable cards,
  server guardEdits, kit WriteExecutions ledger over the app's aligned
  `ai_write_executions` — turn_id widened uuid→varchar); AskUser is a real pause
  with option chips; `POST /ai/turns/{turn}/decide` (floor-gated, queued resume,
  409-stale/422-invented refusals); MCP writes stay immediate via
  `withoutApproval()`. Known limit: pauses resume only within the buffer TTL
  (2h) — older cards render as history (owner may want durable pauses later).
- **#556 callers** (`8bc8acb8`): the five raw-HTTP OpenRouter callers are
  laravel/ai agents (transcriber rides v0.7.0 input_audio); `ModelFallback`
  parse-retry survives app-side; per-ATTEMPT cost accounting so retries never
  bill; billing targets/keys preserved; per-caller feature labels in usage rows.

Kit releases cut this day: **v0.7.0** (gateway maps audio→`input_audio`; 396
tests), **v0.7.1** (module-gated migration loading + per-file flattened
`ai-kit-migrations` publish — fixes approvals migrations loading into consumers
with the module off; 402 tests), **v0.7.2** (ToolChip `dir=auto` + CSS running
ellipsis — hardcoded ltr scrambled Arabic labels' bidi, found by owner on prod
screenshots; consumers must not bake "…" into labels).

Kit gaps logged by the migration (patch-release backlog): `runIntoBuffer()`
takes `$meta` before the fold so post-stream completions can't use it (accept a
Closure); `TurnBuffer::fail()` ends on `error` alone while catodemy-shaped
clients tear down on `done` (opt-in trailing done, or document); mapper has no
cancellation hook (generator-in-front pattern worth documenting);
`timeline.ts` docblock shows an async-iterable example but the reader is
callback-based; `ResumeDecisions::guarded($input, $guard)` helper would save
queued-resume apps ~20 lines. CI learning: PHPStan `--memory-limit` is
per-worker — 2-core runners OOM where 20-core boxes pass (catodemy #557 pins
1G); EXPLAIN-plan tests must drop COMPETING btree indexes, not just ANALYZE +
`enable_seqscan=off` (catodemy SearchLegsTest).

That backlog shipped as **v0.7.3** (#7, `9e608b7`): all six items, plus a
`ClassifiedTool` docblock note that queued dispatches inside a gated write
must be `->afterCommit()`; 409 tests. Closure meta, the opt-in trailing
`done` and `ResumeDecisions::guarded()` are additive — nothing on 0.7.2
changes behaviour.

Next per plan: catodemy clean-prod soak (≥2 weeks gates s-grade per #15) →
retire the kit's transitional proposal module (uqucc + catodemy both off it,
no consumers left) → uqucc TurnBuffer adoption + uqucc kit bump to v0.7.2 (chip
bidi fix applies to its prod) → s-grade branch prep.

## State (2026-08-20) — v0.8.0, the transitional proposal module is gone

**v0.8.0 is the kit's first breaking release**, and it breaks nothing anyone
still runs: the propose → confirm → execute machinery that shipped in v0.3.0
while DECISIONS.md #3 was not visible is **deleted**. uqucc (#129) and catodemy
(#558) both run exclusively on `Approvals\Classified`, so the module had no
consumers left.

Removed: `Proposal` / `ProposalBag` / `ProposalExecutor` / `ProposalStatus` /
`ProposalTrailer` / `ProposedWrite`, `Plan` / `PlanBuilder` / `CachePlanStore`,
`WriteGate` / `WriteGateMode`, `ArrayActionRegistry`, the `PlanStore` /
`ProposableAction` / `ActionRegistry` contracts, the four exceptions that only
that flow threw (`ProposalNotPending`, `UnknownAction`, `ActionValidation`,
`WriteRefused` — the last one's constructor took a `ProposedWrite`, so it could
not outlive it), `Testing\ProposalFactory`, the `ai_proposals` migration, the
provider bindings, the `proposals_table` / `plan_cache_store` /
`plan_ttl_seconds` / `auto_approve` config keys and the two `approvals` lang
strings only `ProposalExecutor` used.

Kept, untouched: the whole `Approvals\Classified` seam, `WriteExecutions` +
the `ai_write_executions` ledger migration (the Classified exactly-once ledger,
NOT proposal machinery), `Approvals\Undo\*`, `Contracts\Classified` +
`Contracts\UndoLedger`, and the MCP `WriteToolAdapter` (it never touched the
proposal classes — it runs a wrapped tool immediately, and
`DestructiveToolAdapter` extends it).

**Upgrading is a no-op** for an app already on the classified seam — see the
README's *Upgrading to v0.8.0* note. Suite: 409 → **349 tests** green (60 tests
covered only deleted code); JS unchanged at 92.

## State (2026-08-18)

Real modules: **gateway**, **catalog** (config + DB sources, `ai-kit:sync-models`,
task routing), **usage**, **safety** (wired live in uqucc via TurnGuard/KillSwitch),
**conversations** (encrypted store, ownership guard, pruning, `reveal()` read seam),
**streaming**, **approvals** (transitional per DECISIONS.md #3, flagged as such in
config + provider) + **undo** (ledger, CompensationApplier, UndoTurn),
**attachments**, **agents** (MCP adapters — `laravel/mcp` is a live dependency),
**credits**. Stub: rag. `Saad\AiKit\Testing` ships fakes + the Proposal factory.
Suite: 372 tests green.

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
2026-08-18) — **merged (`a487967`) and tagged v0.5.0** the same day:

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

2026-08-18 (still later): **uqucc adopted v0.5.0: PR #130 squash-merged** (uqucc
`91c23b9`, pins `^0.5.0` + npm `github:Saad5400/ai-kit#semver:^0.5.0`) — the four
pilot complaints closed: (1) the hand-rolled markdown parser retired for the kit
renderer across FOUR call sites (both assistants + reviews/Show + corpus/
ProposalReview), four duplicate CSS blocks collapsed into `prose.css`; (2) reasoning
+ tool chips stream on both assistant surfaces (thinking closes on first
`delta`/`tool`/terminal, chips upsert by call id, a paused call's chip folds into
the same-id approval card); (3) a no-narration rule in قاعدة التأكيد + all 56 tool
definitions converted to English descriptions (Arabic results kept — arXiv
2601.05101: Arabic tool descriptions degrade tool calling); (4) chat model pinned
to `deepseek/deepseek-v4-flash-0731` with `reasoning_effort low` (3× throughput at
price parity vs the stale 0423 build the old slug resolves to), authoring to
`deepseek-v4-pro-0813`, plus a settings migration rewriting the seeded
`AiSettings->chat_model` row (config alone is only the fallback). uqucc suite 1172
green (2 pre-existing homepage failures). **#129 + #130 both await one Coolify
deploy** — it must run `php artisan migrate` (adds `ai_write_executions`, runs the
chat-model settings migration).

**v0.6.0 — segment timeline + approval forms + AskUser choices** (owner feedback from
prod testing, 2026-08-19; branch `feat/timeline-forms-askuser`, PR open, not merged):

Three complaints from the owner's own use of the v0.5.0 surfaces, each traced to a
place where the kit shipped a channel but left the *model* of it to the app:

- **"Thinking always renders at the top."** Not a wire bug — the events already
  arrive in true chronological order. The apps' client model was one accumulated
  reasoning string beside one accumulated text string, which cannot express a turn
  that talked, thought, called a tool, talked again and thought again. The kit now
  ships the correct model: `js/core/timeline.ts` — `createTimeline(segments)` folds
  the dispatch into an ordered `Segment` list (text / thinking / tool / card),
  merging consecutive same-kind deltas, upserting tool chips **by id in place** so a
  chip never jumps to the end when its call returns, and replacing a chip with the
  same-id approval card (the v0.5.0 fold rule). It mutates the array it is handed, so
  an app passes `reactive([])` / `$state([])` in and reads it straight back.
  `groupSegments()` then applies s-grade's proven grouping — consecutive thinking +
  tool segments collapse into ONE `process` disclosure, `text` and `card` stay
  top-level in place. Two deliberate departures from s-grade's version: consecutive
  text segments are NOT merged back into one answer block (that flattening IS the
  bug), and cards are never swallowed into a process group (an approval card is a
  decision surface, not a progress detail). `ProcessGroup.vue` / `.svelte` render one
  group; `ThinkingDisclosure` is deprecated but still exported for one version.
- **"Every approval argument is a raw editable text input."** The card sent
  `arguments` and the client guessed. Cards now carry `fields` — a server-built form
  schema (`Field` + `FieldWidget`: hidden / readonly / text / number / boolean /
  select / textarea / markdown / code, each with `editable`, `label`, `options`,
  `placeholder`, `value`). A tool declares what inference gets wrong via
  `ClassifiedTool::fields()` (full `Field`, bare widget, or partial spec array);
  everything else is inferred from the pending value, `id` / `*_id` arguments are
  readonly on principle (they address the record the write lands on), and a
  destructive one-click card locks every field. The flat `arguments` map stays for one
  version. **The security half is server-side**: `ApprovalCards::guardEdits()` returns
  the safe argument set — edited values for editable fields, ORIGINAL values for
  readonly/hidden ones silently restored, numbers and booleans cast back to their
  declared types, and a throw when an edit invents an argument key. It is wired where
  it cannot be skipped: `ResumeDecisions::fromClient($input, $cards->editGuard($pending))`
  guards inside the only path from client input to `Decisions`, before a
  `Decision::edit` exists. `ApprovalFields.vue` / `.svelte` render the schema, with a
  slot/snippet seam for per-widget editors.
- **"AskUser questions are bare text prompts."** The tool schema now takes optional
  `options` (2–4 suggested answers, min/max/unique in the schema, sanitized and capped
  server-side), the question card carries them, and `QuestionCard.vue` / `.svelte`
  render the Claude-style shape: option chips, a free-text input as the last option,
  and a skip that rejects. `AskUser::fields()` makes `answer` the one editable field,
  so a guarded question resume restores the model's own `question` / `options` instead
  of trusting the client's echo.
2026-08-19 (same day, owner reviewed prod screenshots): a **UI pass on the same
branch** — the components had to be visually right by default, not merely
mechanically right, because "themeable" had been shipping as "unstyled until the app
does the work":

- **Bidi.** An RTL card rendered `action: create` as a scrambled "create action:".
  Field rows now isolate (`unicode-bidi: isolate` + `<bdi>`): a raw argument name is a
  machine token — mono, `dir="ltr"`, isolated — while a tool-supplied `label` renders
  as `dir="auto"` prose. Readonly values get the same treatment when they look like
  ids or enum members (`js/core/fields.ts` owns that judgement so the Vue and Svelte
  mirrors cannot drift). Readonly fields render as definition rows, never disabled
  inputs. Tool chips and card titles isolate too.
- **Long text.** The prod card put a 1,733-character markdown body in one `<input>`.
  Long-text widgets are an auto-growing editor (`field-sizing: content`) capped at
  ~40vh with internal scroll, mono for markdown/code, plus a character count — which
  is what makes the >120-chars-or-newline inference rule matter.
- **ProcessGroup is a real disclosure**: chevron (rotating up/down, so RTL needs no
  mirror), tool-count badge, opens while `live` and collapses on its own once
  settled — until the user toggles it, after which their choice wins.
- **New `ApprovalCard`** (Vue + Svelte): the full chrome — icon slot, title, status
  chip, reason, preview lines, the form, and a confirm/reject row with confirm first
  in reading order. Destructive cards take the destructive accent on border, tint and
  confirm button, derived from the server flag rather than app guessing. Its `decide`
  event emits exactly what `ResumeDecisions::fromClient()` accepts.
- **QuestionCard answered state**: an optional `answer` / `skipped` renders what was
  actually said instead of a bare "تمت الإجابة".
- **Tokens that work on dark by default.** Neutral fallbacks are now mixed out of
  `currentColor` (`color-mix(in oklab, currentColor N%, transparent)`) instead of
  hardcoded greys, across the components and `prose.css`, so the defaults read on the
  dark admin panel of the screenshots with no app CSS. The `--ai-kit-*` token list is
  documented in the README.
- Suite: 356 PHP tests green (was 336), 79 vitest green (was 47), `tsc --noEmit`
  clean, pint clean. npm package at 0.6.0 with `./timeline` and `./fields` exports.

**v0.6.0 bundle — follow-ups** (2026-08-19; PR #3 **merged `851081c`; v0.6.0
tagged** on top of PR #2 `975d926`, after the owner ruled "ship it all" on the
two build-vs-buy audits — verdict: keep the differentiated modules, adopt three
small libs, delete what OpenRouter/Laravel now do server-side/natively. The
owner declined the OpenRouter privacy-routing change; per his ruling it is not
documented as an open item.) Four packages, each from a completed research audit; the
common theme is code we wrote because nothing better existed at the time, and
one class of corruption nothing was looking for.

- **Three JS swaps.** `js/core/sse.ts` hands framing to `eventsource-parser`'s
  `EventSourceParserStream` — maintained, fuzzed, and already covering what our
  loop did not (leading BOM, bare-CR terminators, a `data:` payload split
  across hundreds of tiny chunks without O(n²) re-concatenation). Our shell
  stays: fetch/abort, `onDone`, the JSON-with-raw-fallback parse, the `id`
  passed through, plus a `maxBufferSize` (1 MiB) so an unterminated proxy body
  rejects instead of growing. One departure from the spec is kept deliberately
  and now lives in a five-line transform: a final frame the server never closed
  with a blank line is still dispatched, because our servers hang up that way
  and a dropped `done` leaves the client spinning. `js/core/markdown.ts` runs
  `remend` over the partial buffer in the LIVE path only, so a half-written
  `**bold` never flashes its asterisks — `finish()` renders the real text, so
  nothing the repair invented survives. And DOMPurify is gone for
  `rehype-sanitize` on the hast tree (schema: default GitHub allow list, plus
  `language-*` on `code`, minus the spec-legacy `irc`/`xmpp` href schemes),
  with the link decorator ported to a rehype plugin that runs AFTER the
  sanitizer so a stripped `javascript:` href cannot be dressed up as
  navigation. Three consequences: no DOM needed (SSR and workers render
  properly instead of degrading to pre-wrap), nothing is serialized-then-
  reparsed, and the allow list is a value the tests can read. The `javascript:`
  gate held; `data:`, `vbscript:` and `file:` were added beside it.
- **Catalog keying.** OpenRouter's payload carries `id` (the stable alias) and
  `canonical_slug` (the dated pin) and they are not interchangeable — pinning
  the dated slug as the routing id freezes an app on a build that will be
  retired, which is the shape of uqucc's `0731`/`0423` confusion. The catalog
  now stores both (`canonical_slug` nullable column via its own migration,
  sync mapping, and a fill-but-never-blank rule so a routine sync cannot wipe a
  pin the config no longer bothers to declare), routes strictly on `id`, and
  resolves a lookup by slug as a second leg so ids written down under the old
  scheme still find their model — coming back on the alias.
- **Server-side routing.** `FallbackChains` is deleted. It cloned an
  `ai.providers.*` entry per chain position to ride laravel/ai's native
  failover, and could only move on errors our own gateway saw and classified.
  `Catalog\ModelRouting` translates the same declarations into OpenRouter's
  `models: [...]` request array (upstream failover on downtime, rate limits,
  moderation AND context-length overflow — the last of which a client-side loop
  cannot detect without first paying for the rejection; priced by whichever
  model answered) and `provider_max_price` into `provider: {max_price}` rather
  than a local filter, so the ceiling binds the model that answers. The gateway
  injects both in `buildStepBody` and never overrides what a caller set.
  `gateway.force_usage_accounting` and its `usage: {include: true}` injection
  are deleted — OpenRouter always returns full usage now; the inline cost and
  generation-id extraction is untouched.
- **Arabic PDF reversal probe.** Poppler returns some Arabic born-digital PDFs
  in VISUAL order — words backwards, ligatures internally scrambled — and
  `junkRatio()` scores that 0.0, because every codepoint IS valid Arabic and
  only the order is wrong, so corrupted text sailed into the model. Local
  parsing stays for clean PDFs; `PdfTextLayer::isReversedArabic()` counts
  common function words against their reversals (their reversals are not
  themselves Arabic words, which is what makes it a decision and not a guess)
  behind an Arabic-dominance gate, and a positive returns null — the same
  "no usable text layer" signal the scanned case already uses, so
  `ExtractionRouter` takes the identical vision branch. No bidi correction in
  PHP. The `-raw` cross-check was dropped on evidence: on real PDFs `-raw`
  reorders words relative to the default for CLEAN Arabic too, so it
  discriminates nothing and costs a second poppler process.
- Suite: 372 PHP tests green (was 356), 92 vitest green (was 79),
  `tsc --noEmit` clean, pint clean. Breaking for consumers:
  `Catalog\FallbackChains` is gone, `ModelDefinition`'s constructor takes
  `canonicalSlug` second, and `gateway.force_usage_accounting` no longer
  exists.

**v0.7.0 — audio attachments on the chat endpoint** (2026-08-19; branch
`feat/gateway-input-audio`, PR open, not merged):

- laravel/ai's OpenRouter `MapsAttachments` knows images and documents and
  throws `InvalidArgumentException` on every `Files\Audio` subclass, so an app
  that wanted audio could not send it through an agent at all — catodemy's
  `GeminiTranscriber` posts raw HTTP `chat/completions` with a hand-built
  `input_audio` part for exactly this reason, and pays for it in no segments,
  no cost capture, no failover, no metering. (The vendor's `audio/transcriptions`
  path is not a substitute: it returns a bare string.) The gateway now overrides
  `mapAttachments()` — audio becomes
  `{"type":"input_audio","input_audio":{"data":…,"format":…}}`, everything else
  is handed to the stock mapper one attachment at a time so its mapping, its
  throw and the given order are unchanged. Base64/local/stored/remote audio and
  uploaded audio files all end up inline base64 (OpenRouter has no URL form for
  audio). `format` is a container token derived from the mime, then the filename
  extension, defaulting to `mp3`; stock's `audioFormat()` is deliberately not
  reused — it belongs to the transcription endpoint and throws on the
  mime-less attachments the chat path must still handle. The vendor
  `MapsAttachments.php` is now pinned in the drift guard.
- Suite: 396 PHP tests green (was 372), pint clean.

**v0.7.1 — migrations gate on the module toggle** (2026-08-19; branch
`fix/module-gated-migrations`, PR open, not merged):

- The root provider loaded the flat `database/migrations` unconditionally, so
  every kit table was created in every consumer whatever `modules.*` said.
  Found by catodemy (PR #550): `modules.approvals => false` plus its own
  pre-existing `ai_write_executions`, and the kit's approvals migrations
  collided anyway — worked around with `Migrator::withoutMigrations()` naming
  each file, a list that goes stale the moment the kit adds a third one.
  The approvals and usage migrations move into `database/migrations/{module}`
  (joining `catalog/` and `undo/`) and are loaded by that module's provider,
  which only registers when its toggle is on — the same predicate, not a
  second one. The root directory keeps only what is genuinely shared: the
  laravel/ai conversation tables. `Support\LoadsKitMigrations` loads a
  directory and registers its files for the `ai-kit-migrations` tag flattened
  per-file, because a subdirectory published into an app's
  `database/migrations` would never run (the migrator globs one level).
- Backward compatible for uqucc (approvals + usage on, already migrated in
  prod): filenames are unchanged and the migrator identifies a migration by
  basename alone (`Migrator::getMigrationName()`), so the moves are invisible
  to `migrations` rows, and its global name sort across all registered paths
  means splitting one directory into several cannot reorder anything —
  including uqucc's own `…_import_ai_usage_into_ai_kit_usage_events` and
  `…_import_admin_pending_actions_into_ai_proposals`, which still land after
  the kit creates the tables they read.
- Suite: 402 PHP tests green (was 396), pint clean.

Tags: v0.1.0 … v0.3.2, **v0.4.0, v0.4.1, v0.5.0, v0.6.0**.

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

- ✅ **retire the transitional proposal module** from the kit — done in **v0.8.0**
  (2026-08-20), once uqucc #129 and catodemy #558 were both live on the classified
  seam and it had no consumers left.
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
extraction from kit; plan flow onto `Approvals\Classified` — the only seam the kit
ships since v0.8.0 (keeps ActionType enum + executor + compensation specifics);
laravel/ai 0.7.2 → ~0.10 + mcp 0.8 → 0.9 (adapters unaffected);
delete dead `ChatMessage.proposals` scaffolding; rewrite the reflection test.

## Working agreements

- Module = own service provider, own config section under `ai-kit.*`, publishable
  migrations tagged `ai-kit-migrations`, Pest feature tests, pint clean.
- Nothing domain-specific enters the kit: no prompts, no app model names, no Arabic
  copy except where it is the mechanism (gateway "answer now" nudge, safety lang files).
- Every extraction preserves the app's test seam (`Agent::fake()` etc.).
- CI matrix (Laravel 12/13 × prefer-lowest/prefer-stable) must stay green; drift-guard
  pins vendor internals we override.

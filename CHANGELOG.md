# Changelog

Releases are git tags on `main`. Earlier history is recorded per milestone in
[`docs/PLAN.md`](docs/PLAN.md); this file starts at 0.11.0 and is the log from here on.

## 0.14.1 — 2026-09-28

Interrupted spend: a stopped or failed turn is priced at the actual provider cost (owner ruling
2026-09-28 — a stopped turn is debited up to the stop, a failed turn stays free but counts toward
the budget). See the README, "Stopped and failed turns: interrupted spend".

- Gateway: a streamed step that does not complete (a stop thrown in, an error frame, a thrown
  failure, an abandoned stream) is recorded too — priced when its `usage.cost` already arrived
  (this changes the old "an error-frame step records nothing"), otherwise as a PENDING generation.
- `SpendCollector` gains `recordPendingGeneration()` and `pendingGenerationIds()` (an id that is
  also a completed generation is never pending); `flush()` clears them. `ContextSpendCollector`
  and `FakeSpendCollector` (+ `assertPending()`) implement them — a custom collector must too.
- `GenerationCostResolver`: `GET /generation?id=` → `data.total_cost`, one look (`fetch()`) or a
  bounded retry window (`resolve()` / `resolveMany()`), with the gateway's provider key.
- `InterruptedSpend::dispatchFor($turnId, $context)`: drains the collector, budgets what was
  priced, prices the pending generations on the spot, queues `ResolveInterruptedSpend` for the
  rest (one look per attempt at 10/30/90/180/300 s, then gives up with a warning), and calls the
  app-bound `InterruptedSpendHandler::resolved($turnId, $costUsd, $generationIds, $context)` at
  most once. `NullInterruptedSpendHandler` is bound by default. Budget writes go through
  `BudgetGuard::recordOnce()` keyed per generation id.
- Config: `ai-kit.spend` (`resolve_interrupted`, `retry_delays_seconds`, the sync window, queue
  and cache store).

## 0.14.0 — 2026-09-28

laravel/ai 1.0. Compatibility with `laravel/ai ^1.0` + `laravel/mcp ^1.0` (1.0 conflicts with
mcp < 1.0), in one release: the compatibility fixes, the conversation store (which the rest is
not usable without), failed and stopped turns stored through 1.0's own failure path, and the
gateway diet. See the README, "Upgrading to laravel/ai 1.0 (conversation store)".

### Compatibility

- Usage: `RecordTurnUsage` read the removed `promptTokens`/`completionTokens` inside
  `rescue()`, so under 1.0 every turn silently lost its usage row and `TurnUsageRecorded`.
  It now reads `inputTokens`/`outputTokens` into the SAME columns (`prompt_tokens`, ...), and
  the now-nullable cache/reasoning counts record as 0. For OpenRouter the numbers do not move:
  its `prompt_tokens`/`completion_tokens` were always inclusive, which is what 1.0 now means.
- `ModelDefinition::displayCostEstimateUsd()` prices the inclusive counts, one rate per side
  (cached input at the base rate — `cache_read_usd_per_million` stays app metadata, #26d).
- `RecordFailover` keys its row on `AgentFailedOver::$invocationId` (0.11+) instead of a
  context lookup.
- Gateway: `replayBlocks:` (was `providerContentBlocks:`), and `reasoning:` +
  `providerToolCalls:` carried through the inspected re-wrap, salvage, leak retry and wrap-up
  merge, so a non-streamed step no longer drops 1.0's reasoning. Streamed reasoning also falls
  back to `reasoning_details` text, as stock 1.0 does.
- Step guard: a leak retry / wrap-up no longer yields its own `StreamStart`. 1.0's
  `TextDelta::combine()` splits steps at `StreamStart`, so the wrap-up persisted
  `narration\n\n\n\nanswer` while the wire said `narration\n\nanswer`; wire, persisted text
  and the merged step now agree.
- Streaming: `StreamResult::$usage` is `?TextUsage`; `StreamEventMapper` skips a sub-agent's
  PRELIMINARY `ToolResult`s (no `tool done` frame, no hook, not collected).
- `failover.overloaded_statuses` default widened to include stock 1.0's 520/522/524.
- Drift guard re-pinned against v1.0.0.
- Tests: `WriteExecutionsTest`'s unique-violation case claims inside a transaction (as
  `claim()` is documented), so it also passes on Postgres.
- Tests: the usage suites' `promptedEvent()` moved to `Tests\Support\UsageTurns`, so
  `TurnFlagsTest` runs alone and under `--parallel`.

### Conversation store

The encrypted store on 1.0's `steps` / `status` schema, plus the migration that moves existing
rows onto it.

- **Migration** `2026_09_28_000000_move_agent_conversation_messages_onto_steps` (phase A): adds
  `steps` (nullable for now) + `status`, makes `tool_calls` / `tool_results` nullable, rebuilds
  `participant_index` with `agent`, and backfills in chunks — encryption-aware (decrypts the 0.10
  columns, re-seals `steps` / `meta` as the bound store writes them), idempotent (only NULL
  `steps`), and, unlike upstream, keeping still-pending approvals as `paused` rows. The 0.10
  columns stay for old workers mid-deploy; phase B drops them in a later release. Guarded;
  pgsql and sqlite.
- New `ai-kit:backfill-conversation-steps` re-runs the backfill for rows an old worker wrote
  after the migration, and folds results an old worker recorded onto converted pauses. The
  encrypted store does the same per conversation on first read (self-heal).
- Backfill safety: a row whose ciphertext does not decrypt (APP_KEY rotated without
  `APP_PREVIOUS_KEYS`) is left untouched with `steps` NULL and reported by id (migration: log +
  console; command: output, non-zero exit) instead of being overwritten; `steps` / `meta` stay
  sealed whenever the source was ciphertext; invalid UTF-8 is substituted, never written as an
  empty column; the UPDATE re-checks `steps IS NULL`; memory is bounded per conversation
  (id + results pre-pass, rows streamed). Phase B must refuse to drop the legacy columns while
  any row has `steps` NULL.
- `EncryptedConversationStore` rewritten onto 1.0: reads through the vendor's `decoded()` /
  `userMessageFrom()` seams; the three vendor methods that UPDATE rows (`resumePausedRow`,
  `forgetReplayBlocks`, `storeApprovalResults`) and `paginateConversationMessages` are mirrored
  with sealing. `content`, `attachments`, `steps`, `meta` (incl. a failed turn's `error`) are
  ciphertext at rest. The overrides 1.0 made redundant (`getLatestConversationMessages`,
  `existingToolResultIds`, `pausedCallIds`) and the protected `encrypt` / `encryptJson` /
  `decrypt` / `decryptRecord` helpers are gone. Traces off now writes a content-only step.
- `ConversationContent` gains `revealJson()`, `conceal()`, `concealJson()`, `encryptsAtRest()`;
  new `StoredSteps` (the steps shape + the content-only reduction) and `StepsBackfill`.
- `StoredApprovals::pending()` wraps the store's `pendingApprovalsFor()` — newest turn only;
  the constructor's `$connection` is ignored.
- `ConversationOwnership` deprecated in favour of the store's `conversationBelongsTo()`; it
  delegates there when a participant type is given. README now warns that 1.0's
  `storeApprovalResults()` no longer scopes to the participant — authorize before resuming —
  and that a mismatched resume fails the pause in place (stock 1.0, kept): apps guard stale
  decisions with the `ResumeDecisions` edit guard before the agent runs.
- Traces off keeps a failed turn's encrypted `meta.error` (DECISIONS.md deviation ledger, #7).
- `ai-kit:prune-conversations` strips traces per row out of the sealed `steps` (keeping the
  text as one step) and empties the legacy columns too. It skips only a `paused` row that is
  still its conversation's newest assistant row; abandoned pauses are stripped.
  Candidates now include rows whose traces live ONLY in `steps` (meta and attachments `'[]'`,
  e.g. converted from a 0.10 row with no meta), which the old `attachments` / `meta` / legacy
  filter never picked; already content-only rows are re-read and skipped, never rewritten. A
  row with `steps` still NULL keeps it NULL (the backfill converts it; phase B's guard still
  sees it), and a row whose `steps` does not decrypt is left untouched and counted in a warning
  instead of being overwritten with its own ciphertext as text.
- Drift guard: per-method pins on every vendor store method the kit mirrors or rides, plus a
  count of the vendor's UPDATE sites.
- Tests: full agent turns through the kit gateway (pause, resume folding into one row, failed
  turn, traces off, mismatched decisions) and the migration against 0.10-shaped encrypted rows.
  `AI_KIT_TEST_DB_URL` runs the suite on Postgres.

### Failed and stopped turns

- Streaming: failed and stopped turns are STORED (owner ruling 2026-09-28). The mapper no
  longer walks away at an in-stream provider `Error`: it emits `error` and pulls once more,
  so 1.0 throws `StreamErrorException` through RememberConversation's catch and a `failed`
  row with the completed steps (tools that ran included) and `meta.error` is written. Before,
  the vendor generator was left suspended and the turn left no row. A recoverable `on(Error)`
  hook meets the same throw and now ends failed with one default `error` frame.
- `TurnRunner`: a failed outcome keeps the partial `StreamResult` on a throw too (was
  `new StreamResult`); a stop throws `TurnCancelledException` into the stream so the stopped
  turn is stored (`status: failed`, `meta.error === TurnCancelledException::MESSAGE`) while
  the outcome stays `cancelled` + `done`. `run()` / `runBuffered()` take an optional `$into`.
- `InterruptedTurns` (`streaming.remember_interrupted_turns`, default on): the step that died
  mid-stream is stored with its partial text, and a turn that died in its first step — which
  stock stores nothing for — keeps the user row and a `failed` assistant row.
- Wire: `error {message, code?}` — an optional machine-readable `code` (`Streaming\ErrorCode`:
  `stream_error`, `provider_unavailable`, `rate_limited`, `killed`, `stale`, `internal_error`),
  in PHP and `js/core/events.ts` (`ErrorPayload.code?`). `TurnBuffer::fail(..., code:)`,
  `TurnOutcome::$failureCode`; the record meta gains `error_code`. Code-less frames stay valid.
- New `Conversations\TurnState` (enum: `completed` / `paused` / `failed` / `stopped`):
  `TurnState::of(MessageStatus|string $status, array|string|null $meta = null)` classifies a
  stored assistant row — `$meta` decoded, or the raw column sealed or plaintext —
  `TurnState::ofMessage(StoredMessage)`, and `->interrupted()` (failed or stopped). `stopped`
  is a `failed` row whose `meta.error` is `TurnCancelledException::MESSAGE`.
- Under the encrypted store a failed or stopped row is sealed like any other: `content`,
  `steps` and `meta` (with `error`) are ciphertext; a resume that dies folds into its paused row
  sealed; the self-heal converts an old worker's rows before a failed turn's history loads.
  With traces off the row keeps one content-only step and `meta = {error}` (sealed), so a stop
  stays recognisable.
- Drift guard: pins `Middleware/RememberConversation.php`, `Gateway/RunContext.php`,
  `Responses/StreamableAgentResponse.php`, `Events/StepFailed.php`, `Events/AgentFailed.php` —
  the failure path the failed / stopped turn storage rides.
- Step guard: a wrap-up (blank final step / step exhaustion) that ends on an in-stream error now
  fails the whole step (the guard returns null, so stock throws `StreamErrorException` and the
  turn is stored failed with the wrap-up's partial text). It used to return the pre-wrap-up step
  as a success after the wire said `error`: the fold stopped following the stream, the vendor
  generator was left suspended and NOTHING was stored — not the user message, not a write that
  had already run — and with `withhold_tools` off the exhausted step's tool calls ran.
- Mapper: a stream that yields past its settled error gets a `StreamErrorException` thrown into
  it (the interrupted step sealed first) instead of being walked away from; `TurnRunner`'s stop
  generator forwards a throw from the fold into the vendor stream.
- Circuit breaker: a step ending on an in-stream `Error` that `ErrorCode` reads as
  `provider_unavailable` / `rate_limited` counts as a failure; only a step that returned a
  response counts as a success. The extra pull after an error let the step complete with null
  and reset the breaker (half-open included), so mid-stream 502 storms never opened it.
- `TurnRunner`: no stop poll after the run's final `StreamEnd` or a `ToolApprovalRequest` — a
  stop landing there stored a finished turn as stopped with no usage row, or turned the pause
  the client already shows into a failed row.
- Conversation id on the failure path: `TurnOutcome::$conversationId` /
  `StreamResult::$conversationId` name the conversation a failed or stopped turn was stored in
  (new `Streaming\StoredConversation::idOf()`, only once the row exists);
  `TurnBuffer::fail(..., conversationId:)` adds `conversation_id` to the `error` frame and the
  record meta, and `runIntoBuffer()` passes it. `js/core/events.ts`:
  `ErrorPayload.conversation_id?`. Apps pass it through so a failed FIRST turn's next message
  or Retry continues the stored thread instead of starting a duplicate.
- Prune keeps a failed turn's sealed `meta.error` (a stopped turn stays `TurnState::Stopped`);
  an error-only meta counts as nothing to strip, so such rows are not rewritten every run.
- Tests: `FailedTurnPersistenceTest` runs on the kit's real migrations (0.10 create + phase A)
  and the real `EncryptedConversationStore`, sealing asserted, traces off, a failed resume and
  a failed turn on top of self-healed rows; also on Postgres via `AI_KIT_TEST_DB_URL`.

### Gateway diet

`ReasoningOpenRouterGateway` no longer carries any copied vendor logic: 1160 → 868 lines,
**−237 net in `src/Gateway`** (−423 / +186, including the new 30-line `StreamTap`).

- Streaming: the ~300-line copy of stock `processTextStream` is gone. Stock 1.0 now runs the
  loop (and emits reasoning from `reasoning` / `reasoning_details` itself); the kit taps each
  decoded chunk via a `parseServerSentEvents()` override on a `StreamTap` body: generation id,
  `usage.cost` and upstream `provider` captured; DeepSeek `reasoning_content` renamed to
  `reasoning`; TTFT stamped at the first reasoning / visible text token; `content` run through
  `MarkupLeakFilter`, with the held tail released as one synthetic final chunk. A thin
  `processTextStream()` wrapper records the spend and re-wraps the step as
  `InspectedStepResponse` (new `InspectedStepResponse::from()`). Differentially fuzzed against
  the old copy (20k random streams): events, text, tool calls, usage, spend and TTFT identical.
  **One deliberate difference**: a single chunk carrying BOTH reasoning and content now closes
  the reasoning block right after that chunk's reasoning (stock's order); the copy closed it
  first, reopened a new block, and left it open across the text.
- Audio: `mapAttachments()` / `mapAudioAttachment()` / `audioPart()` / `inputAudioFormat()`
  removed — stock 1.0 maps Base64Audio, every `StorableFile` Audio and `audio/*` uploads to
  `input_audio`. Kept: a tolerant `audioFormat()` override (parameters like
  `;codecs=opus` dropped, `video/webm`/`video/mp4` from finfo accepted, unlisted containers pass
  through, no container → `mp3`). Lost: the filename-extension fallback, so a Base64Audio with no
  mime is now `mp3` (stock's default) rather than read off its name. The part's keys are now
  `format, data` (stock order). The override also makes OpenRouter transcription tolerant.
- Failover: `failover.overloaded_statuses` now lists ADDITIONS to stock's
  502/503/504/520/522/524 (default `[500, 529]`); stock's list always applies. An app config that
  narrowed the list (uqucc: `[500, 502, 503, 504, 529]`) now also fails over on 520/522/524.
  `recordStepFailure()` drops its `ConnectionException` branch — stock wraps connection failures
  into `ProviderConnectionException`, a `FailoverableException`.
- Drift guard: pins now say which hook leans on which vendor behaviour; `OpenRouterGateway.php`
  (the `audioFormat()` seam) and `StepResponse.php` (every field `from()` copies) are pinned.

## 0.13.1

- Catalog: `z-ai/glm-5.3-flash` re-priced to its live rate — $0.15/$0.50 per M, DOUBLE what it
  published hours earlier on the same day. Re-read prices before trusting them; `sort_order`
  moves with them.
- Catalog: `deepseek/deepseek-v4.1-flash` (key `deepseek-balanced`) joins the menu — DeepSeek's
  newest, the first on their Causal Encoder-Decoder architecture, and the only DeepSeek row that
  takes IMAGE input. $0.15/$0.60 per M, 1.05M context. Not a default candidate at ~4x the chat
  default's input rate; it earns its slot on the capability neither V4 Flash nor V4 Pro has.
- Catalog: `minimax/minimax-m3` (key `minimax-fast`) joins the menu — $0.30/$1.20 per M, 1M
  context, text + image + video, and the fastest of the candidates measured (160 tok/s).
- Both were benched 2026-09-13 on the Arabic tool-call and multi-step-calculation turns and
  answered correctly. Also measured and NOT added: Kimi K3 (Qwen3.8 Max covers the tier at a
  third of the output rate), Tencent HY4 and ByteDance Seed 2.1 Turbo (10-16 s per turn),
  StepFun 3.7 Flash and Ling 3.0 Flash VL (262k / 131k context, far under the rest of the menu).

## 0.13.0

Model unification (DECISIONS.md #28). One menu, four shared lanes, real names.

- Catalog: refreshed to every vendor's current build and re-priced off the live OpenRouter
  models API (2026-09-13) — `deepseek-v4-flash-0731`, `glm-5.3-flash` (new), `qwen3.8-flash`,
  `gpt-5.6-luna`, `gemini-3.5-flash-lite`, `deepseek-v4-pro-0813`, `gemini-3.8-flash`,
  `glm-5.3`, `grok-4.6`, `qwen3.8-max-0902`, `claude-sonnet-5`, `gemini-3.1-pro-preview`,
  `gpt-5.6-terra`, plus `gemini-3.1-flash-lite` on the vision/documents tier.
  `key`s are unchanged, so stored user selections keep resolving; `gemini-2.5-flash-lite`
  leaves the catalog (run the app's sync with `--prune`).
- `chat.model` → `deepseek/deepseek-v4-flash-0731`: cheaper ($0.04/$0.08 vs $0.0476/$0.0952
  per M), bigger context (1.31M) and 2-4x the measured token rate at equal-or-better answers.
- `vision.model` → `google/gemini-3.1-flash-lite` (+ new `vision.fallback_model`): 98%
  character fidelity on a degraded Arabic lecture scan against 83% for `gemini-2.5-flash-lite`,
  and faster. The one lane that costs more than before, deliberately.
- NEW `documents.model` / `documents.fallback_model`: the native file + audio lane (scanned-PDF
  reads, ASR, whole-document summary and translation). Same pair as vision.
- NEW `authoring.model` → `deepseek/deepseek-v4-pro-0813`: admin-triggered, review-gated
  drafting, at a third of the retired `deepseek-v4-pro` rate.
- `Catalog`: `visionFallbackModel()`, `documentsModel()`, `documentsFallbackModel()`,
  `authoringModel()`. `catalog.cheapest`/`smartest` re-pinned to the new floor and ceiling.
- Every catalog entry now ships a `label` — the provider's real model name, which is what a
  picker renders — and a `sort_order` that agrees with its price. Tests hold both, and hold
  every shared lane to a model the catalog declares.

## 0.12.5

- Conversations: `ReplyLanguage` judges the message by the majority script (55%), so a record name in the
  other script («… مقرر Java Programming …») no longer hides the language.

## 0.12.4

- Conversations: `ReplyLanguage::detect()/hint()` — the dominant script of the user's message and the
  one-line envelope hint that keeps a small model answering in that language instead of drifting to
  the language of its tool results.

## 0.12.3

- Approvals: a bare rejection on an approval card now carries a model-facing result
  (`ai-kit::approvals.rejected_result`), so the loop continues and the model tells the user the action
  was not applied — previously laravel/ai ended the turn silently after the denied tool result.

## 0.12.2

- Bench: `LanguageMatches` accepts one-letter Arabic proclitics glued to a Latin token («وCLOs», «بPython»)
  and defaults to a 50% script-ratio threshold, so replies that legitimately quote English material
  (a Java rubric, tutorial slugs) are not failed for their language.

## 0.12.1

- Bench: `LanguageMatches` no longer flags Arabic punctuation after a Latin token («A،», «OMR؟») as a
  mixed-script slip — only letter-to-letter gluing («Letني») fails.

## 0.12.0

- **Bench module** (`ai-kit.modules.bench`, off by default): `Saad\AiKit\Bench` — the
  `TurnDriver`/`ScenarioProvider` contract, `Scenario`/`Turn`/`DecisionPolicy`/`TurnRecord`
  value objects, `BenchRunner` (multi-turn, auto-resume of approval/question cards, budget
  guard, crash isolation), the deterministic grader set + optional `LlmJudge`, `BenchReport`
  (pass rate, pass@k, per-grader failures) with `ReportWriter` transcripts, the `ai-kit:bench`
  command, and `Saad\AiKit\Testing\FakeTurnDriver` for app tests. See `docs/BENCH.md`.
- **Turn robustness** (README → *Turn robustness*). The gateway's new step guard makes a
  tool-using turn always end on a real answer and never shows provider tool-call markup:
  - `MarkupLeakFilter` strips leaked tool-call grammar (DeepSeek `<｜DSML｜…>` and its
    ASCII degradations, generic `<tool_call>` / `<function_calls>` / `<invoke name=`) from
    the streamed and non-streamed text channel, across delta boundaries, before it reaches
    the client or the stored message. A leaked step with no parsed tool call is **salvaged**
    (`DsmlToolCallParser` turns an intact invoke of an offered tool into a real `ToolCall`),
    else **retried once** excluding the leaking upstream via `provider.ignore`, else wrapped
    up. Config: `ai-kit.gateway.markup_leak.{enabled,salvage,retry,ignore_provider,patterns}`.
  - `StepGuard` appends one tool-less answer-now completion INSIDE the same step when a step
    after tool results came back blank (`blank_final`) or the final step still ended in tool
    calls (`step_exhaustion`), so the persisted assistant message carries the answer and the
    extra request is metered like any other. Config: `ai-kit.chat.wrap_up.{on_exhaustion,
    on_blank_final,instruction}`; `WrapUpInstruction::using()` is the closure seam.
  - `ai-kit.gateway.require_parameters` (default on) sends OpenRouter's
    `provider.require_parameters` on tool steps; `ai-kit.chat.reasoning_on_tool_steps`
    (default true) is a benchmark seam; `ai-kit.chat.max_steps` (12) +
    `Agents\StepBudget::default()` is the recommended agent step budget.
  - Rescues are recorded on the usage row's `context` column (`wrap_up`, `markup_leak`,
    `markup_salvaged`, `markup_retried`) via `TurnContext::flag()`, plus log lines.
  - JS: `groupSegments()` files text followed by a later tool call into the process
    disclosure (narration, not reply); `ProcessGroup.items` may hold `TextSegment`, both
    components render it; `{ narration: 'text' }` restores the old grouping. Wire unchanged.

## 0.11.0

- **`ApprovalCards::text(PendingApproval $approval, string $locale): string`** — the same
  approval card `card()` builds, rendered as plain text: title, the tool's human label, a
  warning line when the call is destructive, `• Label: value` per visible argument, the
  tool's preview lines, then the reason. Arabic and English copy under
  `ai-kit::approvals.text.*`; `$locale` switches the whole rendering (the kit's copy and the
  app copy the tool resolves) and restores the previous locale afterwards. No HTML and no
  Markdown — escaping belongs to the transport that adds markup.

  *Why:* **Telegram needs a text card.** R6 puts the teacher lane's writes behind the same
  classified approval primitive the web uses, and a Telegram message has no form to render —
  it gets a text preview plus an inline ✅/❌ keyboard. The trust-bearing fields still have to
  come from the tool instance rather than from model output, so the rendering belongs next to
  `card()` in the kit, where uqucc's bot can reuse it, instead of being written twice app-side.

  Additive: nothing else changed, no config, no migration.

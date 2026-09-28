# Changelog

Releases are git tags on `main`. Earlier history is recorded per milestone in
[`docs/PLAN.md`](docs/PLAN.md); this file starts at 0.11.0 and is the log from here on.

## Unreleased — laravel/ai 1.0, part 2 (conversation store)

The encrypted store on 1.0's `steps` / `status` schema, plus the migration that moves existing
rows onto it. See the README, "Upgrading to laravel/ai 1.0 (conversation store)".

- **Migration** `2026_09_28_000000_move_agent_conversation_messages_onto_steps` (phase A): adds
  `steps` (nullable for now) + `status`, makes `tool_calls` / `tool_results` nullable, rebuilds
  `participant_index` with `agent`, and backfills in chunks — encryption-aware (decrypts the 0.10
  columns, re-seals `steps` / `meta` as the bound store writes them), idempotent (only NULL
  `steps`), and, unlike upstream, keeping still-pending approvals as `paused` rows. The 0.10
  columns stay for old workers mid-deploy; phase B drops them in a later release. Guarded;
  pgsql and sqlite.
- New `ai-kit:backfill-conversation-steps` re-runs the backfill for rows an old worker wrote
  after the migration.
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
- Drift guard: per-method pins on every vendor store method the kit mirrors or rides, plus a
  count of the vendor's UPDATE sites.
- Tests: full agent turns through the kit gateway (pause, resume folding into one row, failed
  turn, traces off, mismatched decisions) and the migration against 0.10-shaped encrypted rows.
  `AI_KIT_TEST_DB_URL` runs the suite on Postgres.

## Unreleased — laravel/ai 1.0, part 1

Compatibility with `laravel/ai ^1.0` + `laravel/mcp ^1.0` (1.0 conflicts with mcp < 1.0).
Released together with part 2 (the conversation store), which it is not usable without.

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

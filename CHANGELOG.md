# Changelog

Releases are git tags on `main`. Earlier history is recorded per milestone in
[`docs/PLAN.md`](docs/PLAN.md); this file starts at 0.11.0 and is the log from here on.

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

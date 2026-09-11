# Changelog

## Unreleased

- **Bench module** (`ai-kit.modules.bench`, off by default): `Saad\AiKit\Bench` — the
  `TurnDriver`/`ScenarioProvider` contract, `Scenario`/`Turn`/`DecisionPolicy`/`TurnRecord`
  value objects, `BenchRunner` (multi-turn, auto-resume of approval/question cards, budget
  guard, crash isolation), the deterministic grader set + optional `LlmJudge`, `BenchReport`
  (pass rate, pass@k, per-grader failures) with `ReportWriter` transcripts, the `ai-kit:bench`
  command, and `Saad\AiKit\Testing\FakeTurnDriver` for app tests. See `docs/BENCH.md`.

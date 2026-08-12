# saad/ai-kit

Shared AI infrastructure for **catodemy**, **s-grade** and **uqucc-laravel**. Private package; the sole requirer of `laravel/ai` and `laravel/mcp` — apps depend on this instead.

Design record: `~/.claude/projects/-home-saad-code/memory/shared-ai-package-decision.md`
Milestone plan: `/home/saad/code/AI-KIT-PLAN.md`

## Modules

Toggled per app via `config/ai-kit.php` → `modules.*`:

| Module | Default | Contents |
|---|---|---|
| `gateway` | on | Canonical `ReasoningOpenRouterGateway`, resilience policy (timeouts, retries, fallback chains, circuit breaker), drift-guard |
| `agents` | on | Agent ↔ MCP tool adapters, Capability bridge |
| `conversations` | on | `EncryptedConversationStore`, retention policies, tool traces |
| `streaming` | on | `TurnRunner` + sinks, resumable SSE buffer (queue-worker generation) |
| `approvals` | on | `Capability` + `Effect`, classified pause on laravel/ai `Approvable`, undo ledger + `UndoTurn`, `AskUser` |
| `attachments` | on | 3-stage extraction pipeline, extract-on-upload, sha-256 cache |
| `usage` | on | `TurnSpend`, canonical usage events, turn traces + TTFT metrics |
| `catalog` | on | `CatalogSource` (config and/or DB), `ai:sync-models` |
| `safety` | on | Central kill switch, `BudgetGuard`, concurrency caps, degraded mode |
| `rag` | off | Hybrid retriever (pgvector + RRF), embedder/chunker |
| `credits` | off | Generalized wallets, `CreditCalculator`, idempotent meter base |

`Saad\AiKit\Testing` ships fakes + exported contract test suites for apps (dev-only, not a toggle).

## Development

```bash
composer install
composer test   # pest
composer lint   # pint --test
```

Tests run on Orchestra Testbench; no live AI calls in CI (recorded fixtures only).

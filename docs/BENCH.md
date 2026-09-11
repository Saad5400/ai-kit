# Bench — scenario benchmark for tool-calling assistants

`Saad\AiKit\Bench` runs realistic multi-turn scenarios against the REAL model (or a fake),
captures the trajectory (text, tool calls/results, approval/question pauses, usage, latency),
grades every run with deterministic graders (plus an optional LLM judge) and writes a JSON +
Markdown report with pass@k over N attempts.

The kit ships the harness, the graders, the report and the `ai-kit:bench` command. The app
ships two things: a **`TurnDriver`** (how to run one of ITS turns) and **`ScenarioProvider`s**
(the stories to run).

Cost in reports is whatever the app's usage rows reported for the turn — never a static-table
estimate (DECISIONS.md #26c). An unreported cost is `null` and sums as zero.

## Enable

```php
// config/ai-kit.php (published)
'modules' => ['bench' => true],

'bench' => [
    'providers' => [App\Ai\Bench\StructureScenarios::class],
    // narration_patterns, tool_error_markers, placeholder_patterns … (see the kit config)
    'judge' => ['enabled' => false, 'model' => null],
],
```

Bind the driver in a service provider:

```php
$this->app->bind(\Saad\AiKit\Bench\TurnDriver::class, \App\Ai\Bench\AppTurnDriver::class);
```

## The driver

```php
interface TurnDriver
{
    /** Run ONE turn as $actor; $conversationId continues a thread; $files: list<{mime,name,path}>. */
    public function run(Authenticatable $actor, string $prompt, array $files = [], ?string $conversationId = null, ?string $model = null): TurnRecord;

    /** Resume a paused turn. $decisions keyed by card id: ['approve'=>true] | ['approve'=>false] | ['answer'=>string]. */
    public function decide(Authenticatable $actor, string $conversationId, array $decisions, ?string $model = null): TurnRecord;
}
```

A driver runs the app's real chat path synchronously (the same agent, tools, approval seam and
usage recording the UI uses), then folds the buffered events into a `TurnRecord`:

| Field | Meaning |
|---|---|
| `turnId`, `conversationId` | the app's ids; the runner passes `conversationId` into the next turn |
| `status` | `done` · `paused` · `error` · `cancelled` |
| `text` | the final user-visible assistant text (`''` if none) |
| `events` | the raw buffer events, `list<{event, data}>` |
| `toolCalls` | `list<{id, name, arguments, status, result, error}>` |
| `pendingCards` | `list<{id, kind: approval\|question, title, options, fields}>` |
| `usage` | `{cost_usd, prompt_tokens, completion_tokens, reasoning_tokens, invocations, duration_ms}` aggregated over the turn's invocations |
| `wallMs`, `error`, `meta` | latency, error text, driver-defined extras (`applied`, `undo`, `steps`…) |

`TurnRecord` is a readonly value object, JSON-serializable, with `TurnRecord::fromArray()` and
`TurnRecord::normalizeUsage()` helpers.

## Authoring scenarios

```php
use Saad\AiKit\Bench\{Scenario, ScenarioProvider, Turn, DecisionPolicy};
use Saad\AiKit\Bench\Graders\{ReplyNotEmpty, NoPlaceholders, NoProviderMarkup, NoStepExhaustion,
    NoNarration, LanguageMatches, ToolsCalled, ToolErrorsAtMost, PausedFor, Callback, CostUnder};

final class StructureScenarios implements ScenarioProvider
{
    public function scenarios(): iterable
    {
        yield new Scenario(
            name: 'structure.create-course-three-sections',
            title: 'إنشاء مقرر بثلاث شعب',
            description: 'Mirrors the prefilled «أنشئ مقرر» button a teacher presses at term start.',
            tags: ['prefilled', 'structure', 'ar'],
            expectedLanguage: 'ar',
            seed: fn () => ['user' => User::factory()->create()],
            actor: fn (array $ctx) => $ctx['user'],
            teardown: fn (array $ctx) => $ctx['user']->delete(),
            turns: [
                new Turn('أنشئ مقرر «الفيزياء ١٠١» بثلاث شعب للفصل الأول'),
                new Turn('احذف الشعبة الثالثة', onPause: DecisionPolicy::approveAll(), graders: [
                    new PausedFor('approval'),
                ]),
            ],
            graders: [
                new ReplyNotEmpty, new NoPlaceholders, new NoProviderMarkup, new NoStepExhaustion,
                new NoNarration, new LanguageMatches,
                new ToolsCalled(all: ['UpsertCourse', 'UpsertSection'], none: ['DeleteCourse']),
                new ToolErrorsAtMost(0),
                new CostUnder(0.05),
                new Callback('db.sections', fn ($run) => Section::where('course_id', $run->context['course_id'])->count() === 2),
            ],
        );
    }
}
```

- **`seed`** runs before the first turn; its return is `ScenarioRun::$context`, handed to
  `actor`, `teardown` and every grader.
- **Turn-scoped graders** run right after their turn (and its auto-resumes); scenario graders
  run after the last turn. `maxCostUsd`/`maxWallMs` on the scenario add built-in verdicts.
- **`DecisionPolicy`** answers pause cards and resumes the SAME turn through `decide()`:
  `approveAll()` · `rejectAll()` · `answer('…' | [...])` (scripted AskUser answers in order,
  approvals approved, a bilingual "go ahead" once answers run out) · `none()` (leave it paused —
  the scenario ends there; graders inspect the cards) · `custom(fn (TurnRecord $paused): array)`.
  `maxRounds` (default 3) guards against ask loops. A turn without a policy uses `approveAll()`.
- A turn that ends `error`/`cancelled`, or paused under `none()`, ends the scenario's turns;
  grading still runs.

## Graders

All in `Saad\AiKit\Bench\Graders`; each returns a `Verdict` (`pass|fail|warn|skip`) with a
message and evidence. Tool names are normalized on both sides (`UpsertCourse` ≡ `upsert_course`).

| Grader | Checks |
|---|---|
| `ReplyNotEmpty` | last text non-blank (a run ending on a card passes) |
| `NoPlaceholders` | no `{url}`, `[link]`, `https://host/`, `example.com`, `<id>` tokens (`bench.placeholder_patterns`) |
| `NoProviderMarkup` | no leaked `<｜DSML｜`, `<tool_call>`, `<|im_start|>`, `</think>`… (`bench.provider_markup_patterns`) |
| `NoStepExhaustion` | no `maximum number of steps` in tool results; no `done` turn with tools but blank text |
| `NoNarration` | warn (fail via `bench.narration_fails` or ctor) on «دعني», «سأقوم», "Let me", "I'll start by"… (`bench.narration_patterns.{ar,en}`) |
| `LanguageMatches` | ≥70 % of letters in the scenario's `expectedLanguage` script (code/URLs stripped); glued tokens like «Letني» fail |
| `ToolsCalled(any, all, none, inOrder)` | tool-name expectations across the run |
| `ToolCallsAtMost(n)` / `ToolErrorsAtMost(n, markers?)` | call count cap; error-flagged or marker-bearing results (`bench.tool_error_markers`) |
| `NoRepeatedReads(readTools?)` | warn on the same read tool + identical args twice in one record |
| `AskedAtMost(n)` / `PausedFor(kind, min)` | question-card cap; at least `min` `approval`/`question` cards |
| `CostUnder(usd)` / `WallUnder(ms)` | run totals |
| `TextContains(any, all?)` / `TextNotContains` / `TextMatches(regex)` | text assertions (all turns, or `lastOnly`) |
| `Callback(name, fn)` | `fn(ScenarioRun): Verdict\|bool\|string` — any app assertion |
| `LlmJudge(rubric, model?, passAt=4)` | OPTIONAL rubric score 1–5 through a structured `JudgeAgent`; off unless `bench.judge.enabled`; never throws (skips) |

Custom graders implement `Grader` (or extend `Graders\AbstractGrader` for the helpers).

## Running

```bash
php artisan ai-kit:bench --list                       # what would run
php artisan ai-kit:bench --tag=structure --attempts=3 # pass@3 on one area
php artisan ai-kit:bench --filter=/^grades\./ --model=openai/gpt-5.4 --max-cost=2
php artisan ai-kit:bench --json --out=storage/bench/today
```

Output lands in `--out` (default `storage/ai-kit/bench/<Ymd-His>`): `report.json` (summary +
every run), `report.md` (scenario table, per-grader failures, slowest / most expensive) and
`transcripts/<scenario>-<attempt>.md` (prompt, tool calls with args + result excerpts, cards,
reply, verdicts). The command exits 0 only when every scenario passed at least once (pass@k)
and the budget was not hit.

Programmatically: `app(BenchRunner::class)->run($scenarios, new RunOptions(attempts: 3))` →
`BenchReport` (`summary()`, `toJson()`, `toMarkdown()`) → `ReportWriter::write($report, $dir)`.
`RunOptions::$beforeScenario`/`$afterScenario` are the hooks for wrapping each attempt in a DB
transaction or snapshot.

## Reading the report

- **pass rate** = passed attempts / attempts; **pass@k** = scenarios that passed at least once.
  A scenario that passes 1/3 is a flake worth a look; 0/3 is a bug.
- **Failures by grader** points at the class of defect (`language_matches` → prompt language
  drift; `no_step_exhaustion` → the model ran out of steps; `tools_called` → routing).
- Transcripts are the ground truth — read the failing attempt's before touching the prompt.

## Testing an app's scenarios

`Saad\AiKit\Testing\FakeTurnDriver` replays scripted `TurnRecord`s (`FakeTurnDriver::done()`,
`::paused()`, `::errored()` builders, `respondWith()` for dynamic replies) and records every
call, so a Pest test can drive the runner without a model and assert the prompts, continued
conversation ids and decisions the runner sent.

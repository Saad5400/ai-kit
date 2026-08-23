<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Each module registers its own service provider when enabled. Apps opt
    | out of what they don't use (uqucc never enables credits; config-only
    | catalogs skip the sync command, etc.).
    |
    */

    'modules' => [
        'gateway' => true,
        'agents' => true,
        'conversations' => true,
        'streaming' => true,
        'approvals' => true,
        'attachments' => true,
        'usage' => true,
        'catalog' => true,
        'safety' => true,
        'rag' => false,
        'credits' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | The canonical ReasoningOpenRouterGateway replaces the stock openrouter
    | driver's text gateway. Retries cover transient statuses only —
    | connection timeouts are deliberately not retried. The final-step nudge
    | is sent to the model when tools are withheld on the last step; it is
    | model-facing text, so it ships bilingual rather than localized.
    |
    */

    'gateway' => [
        'register_openrouter_driver' => true,
        'spend_context_prefix' => 'ai',
        'retry' => [
            'attempts' => 3,
            'backoff_ms' => 500,
            'statuses' => [408, 409, 429, 500, 502, 503, 504],
        ],
        'final_step' => [
            'withhold_tools' => true,
            'message' => 'انتهت خطوات استخدام الأدوات. قدّم الآن إجابتك النهائية للمستخدم نصاً بناءً على ما توصلت إليه، وإن لم تجد المعلومة فقل ذلك صراحةً. '
                .'Tool steps are over — write your complete final answer as plain text now; if the information was not found, say so plainly.',
        ],

        // Statuses that convert to ProviderOverloadedException after retries
        // are exhausted — the trigger for laravel/ai's own provider failover.
        // Model-level fallbacks no longer run here: a catalog entry's
        // `fallbacks` ride into the request as OpenRouter's `models` array
        // and fail over upstream. Stock laravel/ai only maps 503.
        'failover' => [
            'overloaded_statuses' => [500, 502, 503, 504, 529],
        ],

        // Enough step failures inside the window open the circuit for the
        // cooldown; while open, requests to that model fail over immediately
        // without touching the network. Uses the default cache store unless
        // one is named — it must be shared across workers in production.
        'circuit_breaker' => [
            'enabled' => true,
            'cache_store' => env('AI_KIT_BREAKER_CACHE_STORE'),
            'failure_threshold' => 5,
            'window_seconds' => 120,
            'cooldown_seconds' => 60,
            'half_open_seconds' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    |
    | The resumable TurnBuffer keeps each turn's event log in this cache
    | store for `ttl_seconds` — generous enough that a user can close the
    | tab mid-reply and still resume after a break. Use a store shared
    | across web and queue workers in production. The tail loop stops after
    | `max_stream_seconds` (the client's EventSource reconnects with its
    | last id), emits a keepalive comment after `keepalive_seconds` of
    | silence so proxies don't buffer the stream shut, and polls the buffer
    | every `poll_interval_ms`.
    |
    | The log is stored as a header plus pages of `page_size` events, so an
    | append costs the same on the ten-thousandth token as on the first.
    | The producer heartbeats the header on every append (and with
    | `touch()` while silent inside a long tool call); a tail that finds a
    | running turn with no heartbeat for `stale_after_seconds` fails it
    | with the `ai-kit::streaming.stale` message instead of spinning until
    | the TTL — the liveness signal for a worker killed mid-turn. Keep the
    | producer's touch interval well under it. `stale_trailing_done`
    | appends an empty `done` after that stale `error`, for clients that
    | only tear down on `done` (see TurnBuffer::fail()). 0 disables the
    | stale check.
    |
    */

    'streaming' => [
        'cache_store' => env('AI_KIT_STREAMING_CACHE_STORE'),
        'ttl_seconds' => (int) env('AI_KIT_STREAMING_TTL_SECONDS', 7200),
        'max_stream_seconds' => (int) env('AI_KIT_STREAMING_MAX_SECONDS', 180),
        'keepalive_seconds' => (int) env('AI_KIT_STREAMING_KEEPALIVE_SECONDS', 15),
        'poll_interval_ms' => (int) env('AI_KIT_STREAMING_POLL_INTERVAL_MS', 150),
        'page_size' => 64,
        'stale_after_seconds' => 300,
        'stale_trailing_done' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | `encrypt` binds the kit's EncryptedConversationStore over laravel/ai's
    | ConversationStore contract: message content is encrypted with the app
    | key before it touches the database, and pre-encryption plaintext rows
    | still read back. It is ON BY DEFAULT (owner decision, DECISIONS.md #8)
    | with per-app opt-out, and every row written while it is on passes a
    | one-way door: those rows are readable only through this store and only
    | with the app key that wrote them. Keep the key, and do not flip the
    | toggle back and forth. Opting out leaves the vendor store bound (or
    | bind your own). Table names and the connection follow the vendor keys
    | (`ai.conversations.tables.*`, `ai.conversations.connection`).
    |
    | `persist_tool_traces` keeps attachments / tool_calls / tool_results /
    | meta / the approval pause marker on message rows — ENCRYPTED by the
    | store above (usage stays plaintext: aggregate numbers, no user
    | content). ON by default per owner decision DECISIONS.md #7 (traces
    | persist encrypted with short retention); laravel/ai's Approvable
    | pause/resume — the kit's classified approval seam — reconstructs
    | paused turns from these traces, so turning this off also disables
    | resumable approvals. `trace_retention_days` is the SEPARATE short
    | window (#7: 7–30d) beyond which `ai-kit:prune-conversations` strips
    | traces from old rows while the conversation itself lives on.
    |
    | `retention_days` is the idle window `ai-kit:prune-conversations`
    | deletes beyond (the --days option overrides per run). Retention is
    | FOREVER by default (owner decision, DECISIONS.md #9): null makes the
    | delete pass a warning no-op, so only apps that set a window (e.g.
    | uqucc's ~90 days for anonymous threads) ever delete anything. The
    | command fires a ConversationsPruning event with the doomed ids first,
    | so apps can cascade their own per-conversation resources.
    |
    */

    'conversations' => [
        'encrypt' => true,
        'persist_tool_traces' => true,
        'trace_retention_days' => 14,
        'retention_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage
    |--------------------------------------------------------------------------
    |
    | Every completed agent turn writes one canonical row to `table`,
    | recorded from laravel/ai's AgentPrompted / AgentStreamed events — no
    | app code involved. `drain_spend` clears the spend collector after each
    | turn; set it to false while an app still drains the collector itself
    | (dual-write transition). Apps label turns by setting the
    | `feature_context_key` Context value before prompting.
    |
    */

    'usage' => [
        'table' => 'ai_usage_events',
        'drain_spend' => true,
        'feature_context_key' => 'ai-kit.feature',
        'record_failovers' => true,

        // One structured log record per turn / failover attempt, with OTel
        // GenAI attribute names. null channel = the default log channel.
        'trace' => [
            'enabled' => env('AI_KIT_TURN_TRACES', true),
            'channel' => env('AI_KIT_TRACE_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    |
    | The FLEET's shared model registry (DECISIONS.md #26), keyed by
    | provider-facing model id — OpenRouter's `id` (the stable alias), never
    | its `canonical_slug` (the dated pin), which entries record separately so
    | ops can see which build the alias resolved to. This list ships in the
    | KIT so a model decision lands in one place instead of once per app; an
    | app that needs a different menu overrides `ai-kit.catalog.models` in its
    | own published config, but the intent is that none has to.
    |
    | Prices are USD per million tokens and are DISPLAY METADATA ONLY — they
    | tell a user what a model costs, they never price a turn. Billing reads
    | OpenRouter's reported `usage.cost` and nothing else (DECISIONS.md #26c),
    | so a stale row here can misinform but can never mischarge. Every rate
    | below was read from the live models API on 2026-08-24.
    |
    | `fallbacks` declares the failover chain for a model: the gateway sends
    | it as OpenRouter's `models` request array, so the failover happens
    | upstream — on downtime, rate limits, moderation AND context-length
    | overflow — and the turn is priced by whichever model actually answered.
    | `cheapest`/`smartest` feed the SDK's UseCheapestModel /
    | UseSmartestModel attributes.
    |
    | `source` picks where models() reads from: 'config' serves this file
    | live; 'database' serves the `table` rows that `ai-kit:sync-models`
    | materializes from this same file (the reviewed config stays the source
    | of truth — the table adds enable/disable ops control and app metadata).
    | Entries may also declare `tasks` (routing labels like chat/vision/mcq),
    | `tags` (of which `recommended` is enforced: exactly one recommended
    | model per declared task), `provider_max_price` ({prompt, completion}
    | caps, sent as OpenRouter's `provider.max_price` so the ceiling binds
    | the model that answers rather than the one we asked for),
    | `canonical_slug`, `provider`/`provider_model_id`, `enabled`,
    | `sort_order` and a `meta` bag the kit never reads.
    |
    | CAPABILITY WARNING: the DeepSeek V4 entries are TEXT-ONLY on OpenRouter
    | — they declare no `vision` capability, which is precisely why the chat
    | default and the vision default are two different models below. Never
    | route an image at `chat.model`; route it at `vision.model`.
    |
    */

    'catalog' => [
        'provider' => 'openrouter',
        'source' => 'config',
        'table' => 'ai_models',

        // The kit's config is DEEP merged into the app's, so `models` merges
        // BY KEY: an app declaring one extra model keeps the fleet's, which is
        // the point. That also means it cannot REMOVE a shipped model, since a
        // recursive merge has no way to express a deletion. Set this to true
        // to own the whole menu — the app's `models` then replace the shipped
        // ones outright instead of merging with them.
        'replace_shipped_models' => false,
        'cheapest' => 'deepseek/deepseek-v4-flash',
        'smartest' => 'deepseek/deepseek-v4-pro',
        'models' => [

            // ---- Workhorses: the fleet defaults ----------------------------

            'deepseek/deepseek-v4-flash' => [
                'canonical_slug' => 'deepseek/deepseek-v4-flash-20260423',
                'label' => 'DeepSeek V4 Flash',
                'company' => 'DeepSeek',
                'variant' => 'fast',
                'input_usd_per_million' => 0.0489,
                'output_usd_per_million' => 0.0977,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => ['recommended', 'cheapest'],
                'fallbacks' => ['deepseek/deepseek-v4-pro', 'google/gemini-2.5-flash-lite'],
                'sort_order' => 10,
            ],

            'deepseek/deepseek-v4-pro' => [
                'canonical_slug' => 'deepseek/deepseek-v4-pro-20260423',
                'label' => 'DeepSeek V4 Pro',
                'company' => 'DeepSeek',
                'variant' => 'pro',
                'input_usd_per_million' => 0.3969,
                'output_usd_per_million' => 0.7938,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => ['deepseek/deepseek-v4-flash'],
                'sort_order' => 20,
            ],

            // ---- Eyes: the vision tier -------------------------------------
            //
            // Gemini 2.5 Flash Lite is the cheapest vision-capable model that
            // still does tools + structured outputs (needed for extraction
            // JSON). 3.1 Flash Lite is 2.5x dearer and is the quality
            // fallback; 3.5 Flash is the escalation for hard scans.

            'google/gemini-2.5-flash-lite' => [
                'canonical_slug' => 'google/gemini-2.5-flash-lite',
                'label' => 'Gemini 2.5 Flash Lite',
                'company' => 'Google',
                'variant' => 'fast',
                'input_usd_per_million' => 0.10,
                'output_usd_per_million' => 0.40,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['vision'],
                'tags' => ['recommended', 'cheapest_vision'],
                'fallbacks' => ['google/gemini-3.1-flash-lite'],
                'sort_order' => 30,
            ],

            'google/gemini-3.1-flash-lite' => [
                'canonical_slug' => 'google/gemini-3.1-flash-lite-20260507',
                'label' => 'Gemini 3.1 Flash Lite',
                'company' => 'Google',
                'variant' => 'balanced',
                'input_usd_per_million' => 0.25,
                'output_usd_per_million' => 1.50,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['vision'],
                'tags' => [],
                'fallbacks' => ['google/gemini-3.5-flash'],
                'sort_order' => 40,
            ],

            'google/gemini-3.5-flash' => [
                'canonical_slug' => 'google/gemini-3.5-flash-20260519',
                'label' => 'Gemini 3.5 Flash',
                'company' => 'Google',
                'variant' => 'pro',
                'input_usd_per_million' => 1.50,
                'output_usd_per_million' => 9.00,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['vision', 'chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 50,
            ],

            // ---- Premium: the user-selectable tier -------------------------
            //
            // catodemy/s-grade expose these in the model picker. Only
            // reasoning + tool capable models belong here (an agent without
            // reasoning picks tools poorly). They publish rates above the
            // cheap-pool cap, so they deliberately carry no
            // `provider_max_price` — a cap there would exclude every provider
            // and break routing.

            'qwen/qwen3.7-plus' => [
                'canonical_slug' => 'qwen/qwen3.7-plus-20260602',
                'label' => 'Qwen3.7 Plus',
                'company' => 'Qwen',
                'variant' => 'balanced',
                'input_usd_per_million' => 0.32,
                'output_usd_per_million' => 1.28,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 60,
            ],

            'openai/gpt-5.4-mini' => [
                'canonical_slug' => 'openai/gpt-5.4-mini-20260317',
                'label' => 'GPT-5.4 Mini',
                'company' => 'OpenAI',
                'variant' => 'balanced',
                'input_usd_per_million' => 0.75,
                'output_usd_per_million' => 4.50,
                'context_length' => 400000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 70,
            ],

            'z-ai/glm-5.2' => [
                'canonical_slug' => 'z-ai/glm-5.2-20260616',
                'label' => 'GLM-5.2',
                'company' => 'Z.ai',
                'variant' => 'balanced',
                'input_usd_per_million' => 0.966,
                'output_usd_per_million' => 3.036,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 80,
            ],

            'x-ai/grok-4.3' => [
                'canonical_slug' => 'x-ai/grok-4.3-20260430',
                'label' => 'Grok 4.3',
                'company' => 'xAI',
                'variant' => 'pro',
                'input_usd_per_million' => 1.25,
                'output_usd_per_million' => 2.50,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 90,
            ],

            'qwen/qwen3.7-max' => [
                'canonical_slug' => 'qwen/qwen3.7-max-20260520',
                'label' => 'Qwen3.7 Max',
                'company' => 'Qwen',
                'variant' => 'pro',
                'input_usd_per_million' => 1.475,
                'output_usd_per_million' => 4.425,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 100,
            ],

            'anthropic/claude-sonnet-5' => [
                'canonical_slug' => 'anthropic/claude-sonnet-5-20260630',
                'label' => 'Claude Sonnet 5',
                'company' => 'Anthropic',
                'variant' => 'pro',
                'input_usd_per_million' => 2.00,
                'output_usd_per_million' => 10.00,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 110,
            ],

            'openai/gpt-5.4' => [
                'canonical_slug' => 'openai/gpt-5.4-20260305',
                'label' => 'GPT-5.4',
                'company' => 'OpenAI',
                'variant' => 'pro',
                'input_usd_per_million' => 2.50,
                'output_usd_per_million' => 15.00,
                'context_length' => 1050000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 120,
            ],

            'google/gemini-3.1-pro-preview' => [
                'canonical_slug' => 'google/gemini-3.1-pro-preview-20260219',
                'label' => 'Gemini 3.1 Pro',
                'company' => 'Google',
                'variant' => 'pro',
                'input_usd_per_million' => 2.00,
                'output_usd_per_million' => 12.00,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'vision'],
                'tags' => [],
                'fallbacks' => [],
                'sort_order' => 130,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | The shared DEFAULT chat model for the fleet (DECISIONS.md #26, which
    | supersedes #21). Every app inherits this slug and this effort; an app
    | overrides only through AI_KIT_CHAT_MODEL or its own published config —
    | NOT through a database row. uqucc's `AiSettings->chat_model` row was the
    | reason a config change could look applied and change nothing, and #26
    | deletes it.
    |
    | The slug is PINNED by owner ruling: `deepseek/deepseek-v4-flash`. It is
    | the fleet's main chat brain — reasoning + tools + structured outputs,
    | 1M context, and roughly 6x cheaper in and 25x cheaper out than the
    | Gemini Flash Lite it replaces. Ruling #20's objection (no usable "low"
    | effort, "high" crawls) was re-measured on 2026-08-24 and no longer
    | holds: low/medium/high all answer in 4-6 s on the current build.
    |
    | `reasoning_effort` is 'medium' — the owner's "mid reasoning" call. It
    | is shared because thinking quality is what makes the assistant usable
    | for real students, not a per-app taste. Effort barely moves latency on
    | this model, so there is no speed argument for dropping to 'low'.
    |
    | This model is TEXT-ONLY. Anything with an image goes to `vision.model`.
    |
    */

    'chat' => [
        'model' => env('AI_KIT_CHAT_MODEL', 'deepseek/deepseek-v4-flash'),
        'reasoning_effort' => env('AI_KIT_CHAT_REASONING_EFFORT', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision
    |--------------------------------------------------------------------------
    |
    | The shared DEFAULT vision model for the fleet (DECISIONS.md #26b) — the
    | "eyes only" pass that turns an image (a poster, a screenshot, a scanned
    | transcript) into text or structured JSON. It is a SEPARATE decision from
    | chat on purpose: the chat default is text-only, and vision work is
    | bursty, cheap-per-call and quality-sensitive in Arabic.
    |
    | Pinned to `google/gemini-2.5-flash-lite` by owner ruling on cost: at
    | $0.10/$0.40 per M it is the cheapest vision-capable model that still
    | carries tools + structured outputs, 2.5x cheaper than the
    | `google/gemini-3.1-flash-lite` that previously held the slot and which
    | remains its declared fallback.
    |
    */

    'vision' => [
        'model' => env('AI_KIT_VISION_MODEL', 'google/gemini-2.5-flash-lite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | One seam lives here — the DECIDED contract (DECISIONS.md #3), the
    | classified pause on laravel/ai's native `Approvable`: tools extend
    | `Classified\ClassifiedTool`, declare a server-derived Capability
    | (read / write+undoable / destructive), reads run free, undoable
    | writes execute immediately into the undo ledger, destructive calls
    | pause the turn and resume via `Classified\ResumeDecisions`;
    | `Classified\ApprovalCards` renders the cards and `Classified\AskUser`
    | rides the same pause for mid-turn questions.
    |
    | Executed writes claim exactly-once rows in `write_executions_table`,
    | so a resumed or replayed turn never runs the same write twice.
    |
    | The transitional propose → confirm → execute machinery was RETIRED in
    | v0.8.0; its `proposals_table`, `plan_cache_store`, `plan_ttl_seconds`
    | and `auto_approve` keys are gone with it.
    |
    */

    'approvals' => [
        'write_executions_table' => 'ai_write_executions',

        // Turn undo (opt-in): executed writes ledger their compensations in
        // `undo_table` and UndoTurn replays a whole turn in reverse. Keep
        // the undo endpoint OUTSIDE any credit gate — undo spends nothing.
        'undo' => false,
        'undo_table' => 'ai_undo_actions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | The extraction split: images route to the app's vision agent, PDFs
    | take the born-digital decision (a real text layer parses for free via
    | poppler; a scanned or garbled one routes to vision), OOXML/HTML/plain
    | formats parse locally. Text results are cached content-addressed
    | (sha-256 of the bytes + `version` — bump it when the extraction
    | strategy changes). Poppler is best-effort: a missing binary just
    | routes PDFs to vision.
    |
    */

    'attachments' => [
        'cache' => [
            'store' => env('AI_KIT_EXTRACTION_CACHE_STORE'),
            'version' => 'v1',
            'ttl_days' => (int) env('AI_KIT_EXTRACTION_CACHE_TTL_DAYS', 14),
        ],
        'pdf' => [
            'min_chars_per_page' => 80,
            'max_junk_ratio' => 0.10,
            'timeout' => 60,
            'pdftotext_binary' => env('AI_KIT_PDFTOTEXT_BINARY', 'pdftotext'),
            'pdfinfo_binary' => env('AI_KIT_PDFINFO_BINARY', 'pdfinfo'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credits
    |--------------------------------------------------------------------------
    |
    | The cost → credits math and the turn-metering waivers. Margin applies
    | AT CONSUMPTION (never at sale) and conversion always rounds up, so a
    | charge can never fall below cost. `free_turn_max_cost_usd` is the
    | chit-chat waiver ceiling on RESOLVED USD cost (0 disables it). Wallet
    | policy — which wallets pay, resets, caps — stays in the app: bind the
    | CreditDebitor contract to use the CreditMeter.
    |
    */

    'credits' => [
        'margin' => (float) env('AI_KIT_CREDITS_MARGIN', 0.10),
        'credit_unit_usd' => (float) env('AI_KIT_CREDIT_UNIT_USD', 0.0004),
        'usd_to_sar' => 3.75,
        'free_turn_max_cost_usd' => (float) env('AI_KIT_FREE_TURN_MAX_COST_USD', 0.0006),
        'credits_per_message_estimate' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    |
    | Kill switch, daily budget and concurrency state live in this cache
    | store. Use a persistent shared store (redis, database) in production —
    | an engaged kill switch must survive restarts. The daily budget resets
    | at midnight in the given timezone (null = app timezone). A null
    | daily_usd_limit or max_concurrent_turns disables that gate; a
    | daily_usd_limit <= 0 reads as exhausted (an operator kill switch for
    | budget-gated surfaces). `enabled` and `features` back the default
    | config-driven SafetySettings — a feature missing from `features`
    | counts as enabled. Apps with an operator-editable settings store
    | rebind the SafetySettings contract instead of using these keys.
    | `record_spend_from_usage` feeds each metered turn's cost from the
    | usage module into the budget counter (requires the usage module).
    |
    */

    'safety' => [
        'cache_store' => env('AI_KIT_SAFETY_CACHE_STORE'),
        'enabled' => env('AI_KIT_AI_ENABLED', true),
        'features' => [
            // 'chat' => true,
        ],
        'daily_usd_limit' => env('AI_KIT_DAILY_USD_LIMIT') !== null
            ? (float) env('AI_KIT_DAILY_USD_LIMIT')
            : null,
        'timezone' => env('AI_KIT_BUDGET_TIMEZONE'),
        'record_spend_from_usage' => true,
        'max_concurrent_turns' => env('AI_KIT_MAX_CONCURRENT_TURNS') !== null
            ? (int) env('AI_KIT_MAX_CONCURRENT_TURNS')
            : 3,
        'turn_ttl_seconds' => (int) env('AI_KIT_TURN_TTL_SECONDS', 600),
    ],

];

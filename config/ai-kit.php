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
        'force_usage_accounting' => true,
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
        // are exhausted — the trigger for failing over to the next model in
        // a declared chain. Stock laravel/ai only maps 503.
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
    */

    'streaming' => [
        'cache_store' => env('AI_KIT_STREAMING_CACHE_STORE'),
        'ttl_seconds' => (int) env('AI_KIT_STREAMING_TTL_SECONDS', 7200),
        'max_stream_seconds' => (int) env('AI_KIT_STREAMING_MAX_SECONDS', 180),
        'keepalive_seconds' => (int) env('AI_KIT_STREAMING_KEEPALIVE_SECONDS', 15),
        'poll_interval_ms' => (int) env('AI_KIT_STREAMING_POLL_INTERVAL_MS', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | `encrypt` binds the kit's EncryptedConversationStore over laravel/ai's
    | ConversationStore contract: message content is encrypted with the app
    | key before it touches the database, and pre-encryption plaintext rows
    | still read back. It is OPT-IN, and turning it on is a one-way door for
    | every row written while it is on: those rows are readable only through
    | this store and only with the app key that wrote them. Decide before you
    | have traffic, keep the key, and do not flip it back and forth. With it
    | off the vendor store stays bound (or bind your own). Table names and
    | the connection follow the vendor keys (`ai.conversations.tables.*`,
    | `ai.conversations.connection`).
    |
    | `persist_tool_traces` keeps attachments / tool_calls / tool_results /
    | usage / meta on message rows. Off by default — traces can carry user
    | data at rest. laravel/ai's built-in tool-approval pause/resume
    | reconstructs turns from those traces, so enable this if you use it.
    |
    | `retention_days` is the idle window `ai-kit:prune-conversations`
    | deletes beyond (the --days option overrides per run). The command
    | fires a ConversationsPruning event with the doomed ids first, so apps
    | can cascade their own per-conversation resources.
    |
    */

    'conversations' => [
        'encrypt' => false,
        'persist_tool_traces' => false,
        'retention_days' => 30,
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
    | The models the app routes turns to, keyed by provider-facing model id.
    | Prices are USD per million tokens (optional — metering prefers the
    | provider-reported cost). `fallbacks` declares the failover chain for a
    | model; alias provider entries are registered automatically so chains
    | ride laravel/ai's native failover. `cheapest`/`smartest` feed the SDK's
    | UseCheapestModel / UseSmartestModel attributes.
    |
    | `source` picks where models() reads from: 'config' serves this file
    | live; 'database' serves the `table` rows that `ai-kit:sync-models`
    | materializes from this same file (the reviewed config stays the source
    | of truth — the table adds enable/disable ops control and app metadata).
    | Entries may also declare `tasks` (routing labels like chat/mcq),
    | `tags` (of which `recommended` is enforced: exactly one recommended
    | model per declared task), `provider_max_price` ({prompt, completion}
    | routing caps), `provider`/`provider_model_id`, `enabled`, `sort_order`
    | and a `meta` bag the kit never reads.
    |
    */

    'catalog' => [
        'provider' => 'openrouter',
        'source' => 'config',
        'table' => 'ai_models',
        'cheapest' => null,
        'smartest' => null,
        'models' => [
            // 'google/gemini-3.5-flash' => [
            //     'label' => 'Gemini 3.5 Flash',
            //     'input_usd_per_million' => 0.30,
            //     'output_usd_per_million' => 2.50,
            //     'context_length' => 1048576,
            //     'capabilities' => ['tools', 'vision', 'reasoning'],
            //     'fallbacks' => ['deepseek/deepseek-v4-flash'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | The propose → confirm → execute pattern: proposals persist in
    | `proposals_table`, executed plan steps claim exactly-once rows in
    | `write_executions_table`, and proposed plans wait for their confirm
    | turn in the plan cache store for `plan_ttl_seconds` (an abandoned plan
    | quietly lapses). `auto_approve` lets a single non-destructive, undoable
    | step skip the approval card; turn it off to always show the card.
    |
    */

    'approvals' => [
        'proposals_table' => 'ai_proposals',
        'write_executions_table' => 'ai_write_executions',
        'plan_cache_store' => env('AI_KIT_PLAN_CACHE_STORE'),
        'plan_ttl_seconds' => (int) env('AI_KIT_PLAN_TTL_SECONDS', 3600),
        'auto_approve' => true,

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

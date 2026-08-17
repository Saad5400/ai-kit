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
    | still read back. Set it to false to keep the vendor store (or bind
    | your own). Table names and the connection follow the vendor keys
    | (`ai.conversations.tables.*`, `ai.conversations.connection`).
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
        'encrypt' => true,
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
    */

    'catalog' => [
        'provider' => 'openrouter',
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

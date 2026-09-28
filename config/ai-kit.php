<?php

use Saad\AiKit\Gateway\MarkupLeakFilter;

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
        'bench' => false,
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
    | `markup_leak` guards the text channel against a provider that failed to
    | parse the model's own tool-call grammar and streamed it as content
    | (DeepSeek's `<｜DSML｜invoke …>` blocks on some OpenRouter upstreams).
    | Text from the first `patterns` marker to the end of the step never
    | reaches the client or the stored message. When the step parsed no
    | structured tool call either, the gateway first tries to `salvage` the
    | call out of the stripped block (intact DSML `invoke` blocks naming an
    | offered tool run as if the provider had parsed them), else it
    | re-requests the step ONCE (`retry`), excluding the upstream that
    | leaked via OpenRouter's `provider.ignore` when the response named it
    | (`ignore_provider`). What still comes back blank falls through to the
    | `chat.wrap_up` guard. `require_parameters` sends OpenRouter's
    | `provider.require_parameters` on steps that carry tools, so only
    | upstreams that actually support tool calling serve them — the cheapest
    | way to avoid the leak in the first place.
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
        'markup_leak' => [
            'enabled' => true,
            'salvage' => true,
            'retry' => true,
            'ignore_provider' => true,
            'patterns' => MarkupLeakFilter::DEFAULT_PATTERNS,
        ],
        'require_parameters' => true,

        // Statuses that convert to ProviderOverloadedException after retries
        // are exhausted — the trigger for laravel/ai's own provider failover.
        // Model-level fallbacks no longer run here: a catalog entry's
        // `fallbacks` ride into the request as OpenRouter's `models` array
        // and fail over upstream. A superset of stock laravel/ai 1.0's list
        // (502/503/504 + Cloudflare's 520/522/524), adding 500 and 529.
        'failover' => [
            'overloaded_statuses' => [500, 502, 503, 504, 520, 522, 524, 529],
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
    | below was read from the live models API on 2026-09-13.
    |
    | RE-READ THEM. `z-ai/glm-5.3-flash` doubled — $0.075/$0.25 to $0.15/$0.50 —
    | inside the few hours between the first draft of this list and its review.
    | A price here is a snapshot, not a constant; check the live API before
    | trusting one, and re-check `sort_order` when you do, because the menu's
    | order and its ×-multipliers are read against these numbers.
    |
    | `label` is the model's REAL NAME as the provider publishes it ("DeepSeek
    | V4 Flash 0731", "Gemini 3.5 Flash Lite") and it is what every picker in
    | the fleet renders (DECISIONS.md #28b). The older "{company} · {variant}"
    | composition — "DeepSeek · سريع" — is retired: it invented a label the
    | user could not look up, could not compare against anything they had read
    | elsewhere, and could not tell apart from the next DeepSeek row. `company`
    | and `variant` survive as METADATA (the brand mark beside the name, the
    | tier grouping), never as the name itself.
    |
    | ORDER is by price, cheapest first, and `sort_order` encodes it: a picker
    | renders the list in this order and shows each row's cost as a MULTIPLE OF
    | THE DEFAULT (×1, ×3, ×26 …), never a raw $/Mtok a student cannot act on.
    | Keep `sort_order` in step with the rates when you touch a row.
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
    | Entries also carry APP-FACING fields the kit itself never interprets —
    | `key`, `company`, `variant`, `tier`, `effort`,
    | `cache_read_usd_per_million`. They ride through to `ModelDefinition::
    | $extra` so a consuming app (catodemy's model picker, s-grade's registry)
    | can build its own rows from the shared list. `key` in particular is a
    | STABLE app-side identifier: user model selections and
    | `assistant.default_model_key` reference it, so renaming one is a data
    | migration, not an edit.
    |
    | CAPABILITY WARNING: the DeepSeek V4 entries are TEXT-ONLY on OpenRouter
    | — they declare no `vision` capability, which is precisely why the chat
    | default and the vision default are two different models below. Never
    | route an image at `chat.model`; route it at `vision.model`, and route a
    | PDF or an audio file at `documents.model`.
    |
    | DATED IDS: most entries route on an undated alias, but DeepSeek, Qwen and
    | Google publish some builds only under a dated id
    | (`deepseek-v4-flash-0731`, `qwen3.8-max-0902`). Those are the vendor's own
    | product ids on OpenRouter — a DIFFERENT model from the undated
    | `deepseek/deepseek-v4-flash`, which still resolves to the April build —
    | not the `canonical_slug` pin this file warns against elsewhere. Pin them
    | deliberately; the `~vendor/...-latest` auto-redirect aliases are NOT used,
    | because the fleet's default must never change build, quality or price
    | without a review.
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
        'cheapest' => 'deepseek/deepseek-v4-flash-0731',
        'smartest' => 'openai/gpt-5.6-terra',
        'models' => [

            'deepseek/deepseek-v4-flash-0731' => [
                'key' => 'deepseek-fast',
                'canonical_slug' => 'deepseek/deepseek-v4-flash-20260731',
                'label' => 'DeepSeek V4 Flash 0731',
                'company' => 'DeepSeek',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'medium',
                'input_usd_per_million' => 0.04,
                'output_usd_per_million' => 0.08,
                'cache_read_usd_per_million' => 0.008,
                'context_length' => 1310720,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => ['recommended', 'cheapest'],
                'fallbacks' => ['z-ai/glm-5.3-flash', 'deepseek/deepseek-v4-pro-0813'],
                'provider_max_price' => ['prompt' => 0.10, 'completion' => 0.20],
                'sort_order' => 10,
            ],

            'qwen/qwen3.8-flash' => [
                'key' => 'qwen-fast',
                'canonical_slug' => 'qwen/qwen3.8-flash-20260826',
                'label' => 'Qwen3.8 Flash',
                'company' => 'Qwen',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'medium',
                'input_usd_per_million' => 0.15,
                'output_usd_per_million' => 0.47,
                'cache_read_usd_per_million' => 0.016,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 20,
            ],

            'z-ai/glm-5.3-flash' => [
                'key' => 'glm-fast',
                'canonical_slug' => 'z-ai/glm-5.3-flash-20260826',
                'label' => 'GLM 5.3 Flash',
                'company' => 'Z.ai',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'medium',
                // Re-read 2026-09-13 PM: this row DOUBLED from $0.075/$0.25
                // within the same day it was first written. Re-read the live
                // rates before trusting any price in this file.
                'input_usd_per_million' => 0.15,
                'output_usd_per_million' => 0.5,
                'cache_read_usd_per_million' => 0.03,
                'context_length' => 1310720,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => ['deepseek/deepseek-v4-flash-0731'],
                'provider_max_price' => null,
                'sort_order' => 30,
            ],

            'deepseek/deepseek-v4.1-flash' => [
                'key' => 'deepseek-balanced',
                'canonical_slug' => 'deepseek/deepseek-v4.1-flash-20260910',
                'label' => 'DeepSeek V4.1 Flash',
                'company' => 'DeepSeek',
                'variant' => 'balanced',
                'tier' => 'fast',
                'effort' => 'medium',
                'input_usd_per_million' => 0.15,
                'output_usd_per_million' => 0.6,
                'cache_read_usd_per_million' => 0.003,
                'context_length' => 1048576,
                // The ONLY DeepSeek row that can see: V4.1 Flash is the first
                // built on the company's Causal Encoder-Decoder architecture and
                // takes image input, which neither the V4 Flash default nor V4
                // Pro does. That is the reason it earns a slot beside them
                // rather than replacing one.
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => ['deepseek/deepseek-v4-flash-0731'],
                'provider_max_price' => null,
                'sort_order' => 40,
            ],

            'openai/gpt-5.6-luna' => [
                'key' => 'gpt-fast',
                'canonical_slug' => 'openai/gpt-5.6-luna-20260709',
                'label' => 'GPT-5.6 Luna',
                'company' => 'OpenAI',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'medium',
                'input_usd_per_million' => 0.2,
                'output_usd_per_million' => 1.2,
                'cache_read_usd_per_million' => 0.02,
                'context_length' => 1050000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 50,
            ],

            'minimax/minimax-m3' => [
                'key' => 'minimax-fast',
                'canonical_slug' => 'minimax/minimax-m3-20260531',
                'label' => 'MiniMax M3',
                'company' => 'MiniMax',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'medium',
                'input_usd_per_million' => 0.3,
                'output_usd_per_million' => 1.2,
                'cache_read_usd_per_million' => 0.06,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 60,
            ],

            'google/gemini-3.5-flash-lite' => [
                'key' => 'gemini-lite',
                'canonical_slug' => 'google/gemini-3.5-flash-lite-20260721',
                'label' => 'Gemini 3.5 Flash Lite',
                'company' => 'Google',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'low',
                'input_usd_per_million' => 0.3,
                'output_usd_per_million' => 2.5,
                'cache_read_usd_per_million' => 0.03,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                // Also carries `vision`/`document`: it is the declared FALLBACK of
                // the vision + documents default below, so it must be a catalog
                // entry those tasks can resolve.
                'tasks' => ['chat', 'mcq', 'summary', 'vision', 'document'],
                'tags' => [],
                'fallbacks' => ['google/gemini-3.1-flash-lite'],
                'provider_max_price' => null,
                'sort_order' => 70,
            ],

            'deepseek/deepseek-v4-pro-0813' => [
                'key' => 'deepseek-pro',
                'canonical_slug' => 'deepseek/deepseek-v4-pro-20260813',
                'label' => 'DeepSeek V4 Pro 0813',
                'company' => 'DeepSeek',
                'variant' => 'pro',
                'tier' => 'smart',
                'effort' => 'medium',
                'input_usd_per_million' => 0.57948,
                'output_usd_per_million' => 1.73844,
                'cache_read_usd_per_million' => 0.019316,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                // Also the fleet's `authoring` model (see the Authoring block),
                // which resolves through config like chat/vision/documents do —
                // no `authoring` task label, so the one-recommended-per-task
                // invariant stays about the menus users actually pick from.
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => ['deepseek/deepseek-v4-flash-0731'],
                'provider_max_price' => null,
                'sort_order' => 80,
            ],

            'google/gemini-3.8-flash' => [
                'key' => 'gemini-fast',
                'canonical_slug' => 'google/gemini-3.8-flash-20260902',
                'label' => 'Gemini 3.8 Flash',
                'company' => 'Google',
                'variant' => 'balanced',
                'tier' => 'balanced',
                'effort' => 'medium',
                'input_usd_per_million' => 0.75,
                'output_usd_per_million' => 3.75,
                'cache_read_usd_per_million' => 0.075,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 90,
            ],

            'z-ai/glm-5.3' => [
                'key' => 'glm-balanced',
                'canonical_slug' => 'z-ai/glm-5.3-20260816',
                'label' => 'GLM 5.3',
                'company' => 'Z.ai',
                'variant' => 'balanced',
                'tier' => 'balanced',
                'effort' => 'medium',
                'input_usd_per_million' => 1.092,
                'output_usd_per_million' => 3.432,
                'cache_read_usd_per_million' => 0.2028,
                'context_length' => 1310720,
                'capabilities' => ['tools', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 100,
            ],

            'x-ai/grok-4.6' => [
                'key' => 'grok-balanced',
                'canonical_slug' => 'x-ai/grok-4.6-20260810',
                'label' => 'Grok 4.6',
                'company' => 'xAI',
                'variant' => 'balanced',
                'tier' => 'balanced',
                'effort' => 'medium',
                'input_usd_per_million' => 2.0,
                'output_usd_per_million' => 6.0,
                'cache_read_usd_per_million' => 0.5,
                'context_length' => 500000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 110,
            ],

            'qwen/qwen3.8-max-0902' => [
                'key' => 'qwen-pro',
                'canonical_slug' => 'qwen/qwen3.8-max-20260902',
                'label' => 'Qwen3.8 Max',
                'company' => 'Qwen',
                'variant' => 'pro',
                'tier' => 'smart',
                'effort' => 'medium',
                'input_usd_per_million' => 2.0,
                'output_usd_per_million' => 6.0,
                'cache_read_usd_per_million' => 0.25,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 120,
            ],

            'anthropic/claude-sonnet-5' => [
                'key' => 'claude-balanced',
                'canonical_slug' => 'anthropic/claude-sonnet-5-20260630',
                'label' => 'Claude Sonnet 5',
                'company' => 'Anthropic',
                'variant' => 'balanced',
                'tier' => 'balanced',
                'effort' => 'medium',
                'input_usd_per_million' => 2.0,
                'output_usd_per_million' => 10.0,
                'cache_read_usd_per_million' => 0.2,
                'context_length' => 1000000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 130,
            ],

            'google/gemini-3.1-pro-preview' => [
                'key' => 'gemini-pro',
                'canonical_slug' => 'google/gemini-3.1-pro-preview-20260219',
                'label' => 'Gemini 3.1 Pro',
                'company' => 'Google',
                'variant' => 'pro',
                'tier' => 'smart',
                'effort' => 'medium',
                'input_usd_per_million' => 2.0,
                'output_usd_per_million' => 12.0,
                'cache_read_usd_per_million' => 0.2,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => [],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 140,
            ],

            'openai/gpt-5.6-terra' => [
                'key' => 'gpt-pro',
                'canonical_slug' => 'openai/gpt-5.6-terra-20260709',
                'label' => 'GPT-5.6 Terra',
                'company' => 'OpenAI',
                'variant' => 'pro',
                'tier' => 'smart',
                'effort' => 'medium',
                'input_usd_per_million' => 2.0,
                'output_usd_per_million' => 12.0,
                'cache_read_usd_per_million' => 0.2,
                'context_length' => 1050000,
                'capabilities' => ['tools', 'vision', 'reasoning', 'structured_outputs'],
                'tasks' => ['chat', 'mcq', 'summary'],
                'tags' => ['smartest'],
                'fallbacks' => [],
                'provider_max_price' => null,
                'sort_order' => 150,
            ],

            'google/gemini-3.1-flash-lite' => [
                'key' => 'gemini-vision',
                'canonical_slug' => 'google/gemini-3.1-flash-lite-20260507',
                'label' => 'Gemini 3.1 Flash Lite',
                'company' => 'Google',
                'variant' => 'fast',
                'tier' => 'fast',
                'effort' => 'low',
                'input_usd_per_million' => 0.25,
                'output_usd_per_million' => 1.5,
                'cache_read_usd_per_million' => 0.025,
                'context_length' => 1048576,
                'capabilities' => ['tools', 'vision', 'audio', 'file', 'reasoning', 'structured_outputs'],
                // NOT offered for `chat`: this is the eyes/ears lane, chosen on
                // transcription fidelity rather than conversation quality, and
                // it must not appear in the user-facing picker.
                'tasks' => ['vision', 'document'],
                'tags' => ['recommended', 'cheapest_vision'],
                'fallbacks' => ['google/gemini-3.5-flash-lite'],
                'provider_max_price' => null,
                'sort_order' => 200,
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
    | The slug is PINNED by owner ruling: `deepseek/deepseek-v4-flash-0731`
    | (DECISIONS.md #28). It is the fleet's main chat brain — reasoning + tools
    | + structured outputs, 1.31M context, and the cheapest capable agentic
    | model on OpenRouter at $0.04/$0.08 per M.
    |
    | It REPLACES `deepseek/deepseek-v4-flash` (the April "0423" build the
    | undated alias still resolves to) and it wins on every axis the owner set
    | for a default swap — same-or-cheaper, same-or-faster, same-or-smarter:
    |
    |                          $/M in   $/M out   ctx      measured tok/s
    |   v4-flash (0423)         0.0476   0.0952   1.05M     49-105   <- was
    |   v4-flash-0731           0.0400   0.0800   1.31M    147-240   <- now
    |
    | Measured 2026-09-13 over three representative turns (an Arabic tool-call
    | request, an Arabic multi-step calculation, a strict-JSON summary), twice
    | each: 0731 answered in the same or less wall-clock every time at 2-4x the
    | token rate, with correct answers throughout and cleaner structure. It is
    | DeepSeek's re-post-trained V4 Flash revision, explicitly tuned for
    | "coding, reasoning, and agent workflows" — which is what this assistant
    | is. Ruling #20's objection (no usable "low" effort, "high" crawls) was
    | re-measured on 2026-08-24 and no longer holds.
    |
    | `reasoning_effort` is 'medium' — the owner's "mid reasoning" call. It
    | is shared because thinking quality is what makes the assistant usable
    | for real students, not a per-app taste. Effort barely moves latency on
    | this model, so there is no speed argument for dropping to 'low'.
    |
    | This model is TEXT-ONLY. Anything with an image goes to `vision.model`.
    |
    | `max_steps` is the recommended step budget for a chat agent — read it
    | through `Saad\AiKit\Agents\StepBudget::default()` from the agent's
    | `maxSteps()`. A step is one model invocation; the LAST step is always
    | sent without tools plus the answer-now nudge (`gateway.final_step`), so
    | the budget is "tool rounds + 1" and a turn can never end on an
    | unexecuted tool call. 12 leaves ~11 tool rounds, above what any
    | observed teacher request needed while still bounding a runaway loop.
    |
    | `wrap_up` is the step guard's guaranteed final answer (StepGuard):
    | `on_exhaustion` appends a tool-less answer-now completion when the
    | final step STILL ended in tool calls (withholding off); `on_blank_final`
    | does the same when a step that followed tool results (or a stripped
    | markup leak) came back with empty text — the "it narrated, then went
    | silent" thread. Either runs INSIDE the same step, so the persisted
    | assistant message carries the answer and the wrap-up's cost is recorded
    | like any other invocation. `instruction` is the nudge sent to the model
    | — null uses `gateway.final_step.message`; a literal string or a lang
    | key overrides it; `WrapUpInstruction::using()` is the closure seam.
    |
    | `reasoning_on_tool_steps` — when false, the `reasoning` request field
    | is dropped on steps that follow tool results, for benchmarking the
    | "reasoning off for agentic turns" advice on the DeepSeek card. The
    | default keeps today's behaviour (ruling #26a: effort is not dialled
    | down by the kit).
    |
    */

    'chat' => [
        'model' => env('AI_KIT_CHAT_MODEL', 'deepseek/deepseek-v4-flash-0731'),
        'reasoning_effort' => env('AI_KIT_CHAT_REASONING_EFFORT', 'medium'),
        'max_steps' => (int) env('AI_KIT_CHAT_MAX_STEPS', 12),
        'reasoning_on_tool_steps' => true,
        'wrap_up' => [
            'on_exhaustion' => true,
            'on_blank_final' => true,
            'instruction' => null,
        ],
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
    | Pinned to `google/gemini-3.1-flash-lite` (DECISIONS.md #28a), which is
    | ALSO the `documents` model below — one pair of eyes for the whole fleet.
    | This supersedes the 2026-08-24 cost ruling that put
    | `google/gemini-2.5-flash-lite` here: that choice was made on price alone
    | and the accuracy it bought is not good enough for Arabic course material.
    |
    | Measured 2026-09-13 on a degraded Arabic lecture scan (rotated, blurred,
    | noisy; Arabic-Indic numerals, mixed AR/EN math, a deadline and a grade
    | the downstream summary must not get wrong), three reps each — character
    | similarity against ground truth / load-bearing facts recovered / latency:
    |
    |   gemini-3.1-flash-lite   98.0 98.3 98.1 %   10/10 every rep   2.3-3.2 s  <- now
    |   gemini-2.5-flash        92.1 93.7 94.6 %    9-10/10          3.9-4.4 s
    |   qwen3.8-flash           93.8 87.8 90.1 %   10/10             4.8-8.3 s
    |   gemini-2.5-flash-lite   83.0 83.0 83.2 %    9/10             3.2-4.5 s  <- was
    |   gemini-3.5-flash-lite   98.2 71.8 74.8 %    9-10/10          5.1-7.1 s
    |   gemini-3.8-flash        70.1 %  (ran away to the token cap)  19.3 s
    |   glm-5.3-flash            0.0 %  (looped to the token cap)    29.1 s
    |
    | The winner is the most accurate AND the fastest AND cheaper than the
    | `google/gemini-2.5-flash` two apps were pinning privately. It costs 2.5x
    | the retired 2.5-flash-lite rate, which is the one place this release
    | spends more: a vision pass is a handful of pages, and 83% on a scan is
    | not a saving — it is a wrong grade in a student's summary.
    |
    | `fallback_model` is the sturdier `google/gemini-3.5-flash-lite`, retried
    | when the primary errors or returns unusable output twice in a row. It
    | scores as well as the primary at its best and materially worse at its
    | worst, which is exactly the right shape for a second attempt.
    |
    */

    'vision' => [
        'model' => env('AI_KIT_VISION_MODEL', 'google/gemini-3.1-flash-lite'),
        'fallback_model' => env('AI_KIT_VISION_FALLBACK_MODEL', 'google/gemini-3.5-flash-lite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents (native file + audio)
    |--------------------------------------------------------------------------
    |
    | The fleet's lane for work the provider reads FROM A FILE rather than from
    | text we extracted first: a scanned PDF read natively (no per-page OCR
    | fee), a lecture recording transcribed to captions, a long document
    | summarized or translated whole. It is a THIRD decision beside chat and
    | vision because it has a hard capability floor the other two do not —
    | the model must accept `file` and `audio` input, which on OpenRouter today
    | means the Gemini family and little else.
    |
    | Same pair as `vision` and for the same measured reason: this is the ears
    | and eyes lane, and the pair was already catodemy's (`ai.model` /
    | `ai.fallback_model`) before this ruling — the release moves that choice
    | into the kit instead of leaving three apps to pin their own. s-grade's
    | private `google/gemini-2.5-flash` document pin resolves here now, which
    | makes its scanned-PDF path cheaper AND more accurate.
    |
    | `fallback_model` is what a caller retries onto when the primary errors OR
    | returns unusable output twice in a row — so a transient miss costs a
    | retry, not the job. On AUDIO the fallback is the cheaper of the two, so an
    | ASR retry never costs more than the attempt it replaces.
    |
    */

    'documents' => [
        'model' => env('AI_KIT_DOCUMENTS_MODEL', 'google/gemini-3.1-flash-lite'),
        'fallback_model' => env('AI_KIT_DOCUMENTS_FALLBACK_MODEL', 'google/gemini-3.5-flash-lite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authoring
    |--------------------------------------------------------------------------
    |
    | The heavyweight lane for admin-triggered, review-gated drafting: writing
    | a page from a source document, proposing revisions, drafting a course
    | report's prose from real grade data. Rare, high-value, never on a
    | student's latency budget — so it buys reasoning depth the chat default
    | does not need to.
    |
    | Pinned to `deepseek/deepseek-v4-pro-0813`, the GA build of DeepSeek V4
    | Pro. It supersedes `deepseek/deepseek-v4-pro` (the April build the
    | undated alias resolves to) on price alone: $0.579/$1.738 against
    | $1.60/$3.20 per M for an older revision — the same family, newer, at
    | roughly a third of the rate. uqucc had already found this and pinned it
    | privately; s-grade was drafting narratives on `google/gemini-2.5-flash`.
    | Both resolve here now.
    |
    */

    'authoring' => [
        'model' => env('AI_KIT_AUTHORING_MODEL', 'deepseek/deepseek-v4-pro-0813'),
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

    /*
    |--------------------------------------------------------------------------
    | Bench
    |--------------------------------------------------------------------------
    |
    | The scenario benchmark harness (`ai-kit:bench`). The app binds its own
    | `Saad\AiKit\Bench\TurnDriver` and lists its ScenarioProvider classes
    | here. Cost in reports is whatever the app's usage rows reported for
    | the turn — never a static-table estimate (DECISIONS.md #26c). The
    | narration patterns feed the NoNarration grader (apps extend the
    | lists); `tool_error_markers` feed ToolErrorsAtMost. The LLM judge is
    | off unless enabled — it spends real money.
    |
    */

    'bench' => [
        'providers' => [
            // App\Ai\Bench\StructureScenarios::class,
        ],
        'output_dir' => env('AI_KIT_BENCH_OUTPUT_DIR', 'ai-kit/bench'),
        'default_attempts' => 1,
        'narration_patterns' => [
            'ar' => ['دعني', 'دعوني', 'سأقوم', 'سأبدأ', 'لنبدأ', 'أولاً، دعني', 'أولا، دعني', 'خليني', 'سوف أقوم', 'سأتحقق أولاً'],
            'en' => ['Let me', 'I\'ll start by', 'I will start by', 'First, I', 'I will now', 'I\'m going to', 'I am going to', 'Now I\'ll', 'Now I will'],
        ],
        'narration_fails' => false,
        'tool_error_markers' => ['error', 'not permitted', 'failed', 'unauthorized', 'forbidden', 'exception', 'خطأ', 'غير مسموح', 'فشل'],
        'placeholder_patterns' => [
            '/\{[a-z_]*(url|link|id|name|href)[a-z_]*\}/iu',
            '/\[(link|url|رابط)\]/iu',
            '/https?:\/\/host\//i',
            '/https?:\/\/(www\.)?example\.(com|org|net)/i',
            '/<(id|url|link|name|activity_id|course_id)>/i',
            '/\{\{[^}]+\}\}/u',
        ],
        'provider_markup_patterns' => [
            '<｜DSML｜', '<|DSML|>', '<｜tool▁calls▁begin｜>', '<｜tool▁call▁begin｜>', '<｜tool▁sep｜>',
            '<｜begin▁of▁sentence｜>', '<｜end▁of▁sentence｜>', '<｜User｜>', '<｜Assistant｜>',
            '<tool_call>', '</tool_call>', '<|im_start|>', '<|im_end|>', '<think>', '</think>',
            '<|start_header_id|>', '<|eot_id|>', '[TOOL_CALLS]', '<function_calls>', '</function_calls>',
        ],
        'step_exhaustion_markers' => ['maximum number of steps'],
        'judge' => [
            'enabled' => env('AI_KIT_BENCH_JUDGE_ENABLED', false),
            'model' => env('AI_KIT_BENCH_JUDGE_MODEL'),
            'provider' => env('AI_KIT_BENCH_JUDGE_PROVIDER', 'openrouter'),
            'pass_at' => 4,
        ],
    ],

];

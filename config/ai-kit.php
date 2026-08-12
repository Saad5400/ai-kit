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
    | daily_usd_limit or max_concurrent_turns disables that gate.
    |
    */

    'safety' => [
        'cache_store' => env('AI_KIT_SAFETY_CACHE_STORE'),
        'daily_usd_limit' => env('AI_KIT_DAILY_USD_LIMIT') !== null
            ? (float) env('AI_KIT_DAILY_USD_LIMIT')
            : null,
        'timezone' => env('AI_KIT_BUDGET_TIMEZONE'),
        'max_concurrent_turns' => env('AI_KIT_MAX_CONCURRENT_TURNS') !== null
            ? (int) env('AI_KIT_MAX_CONCURRENT_TURNS')
            : 3,
        'turn_ttl_seconds' => (int) env('AI_KIT_TURN_TTL_SECONDS', 600),
    ],

];

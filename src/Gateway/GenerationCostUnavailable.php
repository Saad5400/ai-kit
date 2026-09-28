<?php

namespace Saad\AiKit\Gateway;

use RuntimeException;

/**
 * A generation's cost can NEVER be fetched with this configuration — no
 * key, a key OpenRouter refuses (401/403), or `ai-kit.spend.provider`
 * naming a provider that is not OpenRouter — as opposed to "not yet",
 * which {@see GenerationCostResolver} reports as null. Retrying cannot
 * help, so callers stop at once and log it as the configuration error it is.
 */
class GenerationCostUnavailable extends RuntimeException {}

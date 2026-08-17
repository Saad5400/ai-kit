<?php

namespace Saad\AiKit\Safety;

use Illuminate\Contracts\Config\Repository;

/**
 * Default SafetySettings backed by the ai-kit config. Values are read live
 * so runtime config changes take effect immediately. A feature absent from
 * `safety.features` counts as enabled — only toggles an operator declares
 * can turn a surface off.
 */
class ConfigSafetySettings implements SafetySettings
{
    public function __construct(protected Repository $config) {}

    public function enabled(?string $feature = null): bool
    {
        if (! (bool) $this->config->get('ai-kit.safety.enabled', true)) {
            return false;
        }

        if ($feature === null) {
            return true;
        }

        $features = (array) $this->config->get('ai-kit.safety.features', []);

        return (bool) ($features[$feature] ?? true);
    }

    public function dailyBudgetUsd(): ?float
    {
        $limit = $this->config->get('ai-kit.safety.daily_usd_limit');

        return $limit === null ? null : (float) $limit;
    }
}

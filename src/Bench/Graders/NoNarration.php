<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\TextScrub;
use Saad\AiKit\Bench\Verdict;

/**
 * Warns (or fails, when configured) when any reply text opens with or
 * contains process narration — «دعني…», «سأقوم…», "Let me…", "I'll start
 * by…" — instead of doing the thing. Patterns come from
 * `ai-kit.bench.narration_patterns` (AR + EN lists) unless overridden.
 */
final class NoNarration extends AbstractGrader
{
    /** @var array<string, list<string>> */
    private array $defaultPatterns = [
        'ar' => ['دعني', 'دعوني', 'سأقوم', 'سأبدأ', 'لنبدأ', 'أولاً، دعني', 'أولا، دعني', 'خليني', 'سوف أقوم', 'سأتحقق أولاً'],
        'en' => ['Let me', 'I\'ll start by', 'I will start by', 'First, I', 'I will now', 'I\'m going to', 'I am going to', 'Now I\'ll', 'Now I will'],
    ];

    /**
     * @param  list<string>|array<string, list<string>>|null  $patterns  null reads config
     * @param  bool|null  $fail  null reads `ai-kit.bench.narration_fails`
     * @param  bool  $startOnly  only flag a pattern at the start of a paragraph
     */
    public function __construct(private ?array $patterns = null, private ?bool $fail = null, private bool $startOnly = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $patterns = $this->flatten($this->patterns ?? $this->config('narration_patterns', $this->defaultPatterns));
        $fail = $this->fail ?? (bool) $this->config('narration_fails', false);
        $hits = [];

        foreach ($run->turns as $index => $record) {
            $text = TextScrub::stripCodeAndUrls($record->text);

            if (trim($text) === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                $quoted = preg_quote($pattern, '/');
                $regex = $this->startOnly ? "/(^|\\n)\\s*{$quoted}/iu" : "/(^|[\\s\\p{P}]){$quoted}/iu";

                if (preg_match($regex, $text)) {
                    $hits[] = ['turn' => $index, 'pattern' => $pattern, 'excerpt' => TextScrub::excerpt($text, 120)];
                }
            }
        }

        if ($hits === []) {
            return $this->pass();
        }

        $message = 'Narration detected: '.implode(', ', array_unique(array_column($hits, 'pattern')));

        return $fail ? $this->fail($message, ['hits' => $hits]) : $this->warn($message, ['hits' => $hits]);
    }

    /**
     * @param  list<string>|array<string, list<string>>  $patterns
     * @return list<string>
     */
    private function flatten(array $patterns): array
    {
        $flat = [];

        foreach ($patterns as $value) {
            foreach ((array) $value as $pattern) {
                $flat[] = (string) $pattern;
            }
        }

        return array_values(array_filter($flat, fn (string $p): bool => $p !== ''));
    }
}

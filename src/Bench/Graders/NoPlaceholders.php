<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Fails on unfilled template tokens ({url}, [link], https://host/, example.com, <id>) in user-visible text. */
final class NoPlaceholders extends AbstractGrader
{
    /** @var list<string> */
    private array $defaultPatterns = [
        '/\{[a-z_]*(url|link|id|name|href)[a-z_]*\}/iu',
        '/\[(link|url|رابط)\]/iu',
        '/https?:\/\/host\//i',
        '/https?:\/\/(www\.)?example\.(com|org|net)/i',
        '/<(id|url|link|name|activity_id|course_id)>/i',
        '/\{\{[^}]+\}\}/u',
    ];

    /**
     * @param  list<string>|null  $patterns  regexes; null reads `ai-kit.bench.placeholder_patterns`
     */
    public function __construct(private ?array $patterns = null) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $patterns = $this->patterns ?? $this->config('placeholder_patterns', $this->defaultPatterns);
        $hits = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $run->allText(), $matches)) {
                array_push($hits, ...$matches[0]);
            }
        }

        $hits = array_values(array_unique($hits));

        return $hits === []
            ? $this->pass()
            : $this->fail('Placeholder tokens in reply: '.implode(', ', $hits), ['hits' => $hits]);
    }
}

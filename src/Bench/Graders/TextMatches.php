<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Reply text matches the regex (a full PCRE pattern with delimiters). */
final class TextMatches extends AbstractGrader
{
    public function __construct(private string $regex, private bool $lastOnly = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $text = $this->lastOnly ? $run->lastText() : $run->allText();
        $result = @preg_match($this->regex, $text, $matches);

        if ($result === false) {
            return $this->fail("Invalid regex {$this->regex}.");
        }

        return $result === 1
            ? $this->pass('Matched: '.($matches[0] ?? ''))
            : $this->fail("No match for {$this->regex}.");
    }
}

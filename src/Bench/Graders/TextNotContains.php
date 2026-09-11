<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Reply text contains none of the needles (case-insensitive). */
final class TextNotContains extends AbstractGrader
{
    /**
     * @param  list<string>  $needles
     */
    public function __construct(private array $needles, private bool $lastOnly = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $text = $this->lastOnly ? $run->lastText() : $run->allText();
        $found = array_values(array_filter($this->needles, fn (string $needle): bool => mb_stripos($text, $needle) !== false));

        return $found === []
            ? $this->pass()
            : $this->fail('Forbidden text present: '.implode(', ', $found), ['found' => $found]);
    }
}

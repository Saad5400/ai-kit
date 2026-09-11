<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Reply text (all turns, or the last only) contains any — or all — of the needles (case-insensitive). */
final class TextContains extends AbstractGrader
{
    /**
     * @param  list<string>  $any
     */
    public function __construct(private array $any, private bool $all = false, private bool $lastOnly = false) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $text = $this->lastOnly ? $run->lastText() : $run->allText();
        $found = array_values(array_filter($this->any, fn (string $needle): bool => mb_stripos($text, $needle) !== false));
        $missing = array_values(array_diff($this->any, $found));

        $ok = $this->all ? $missing === [] : $found !== [];

        return $ok
            ? $this->pass('Found: '.implode(', ', $found))
            : $this->fail(($this->all ? 'Missing: ' : 'None of: ').implode(', ', $this->all ? $missing : $this->any), ['found' => $found]);
    }
}

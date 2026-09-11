<?php

namespace Saad\AiKit\Bench;

/**
 * One scripted user turn inside a scenario.
 */
final class Turn
{
    /**
     * @param  list<array{mime: string, name: string, path: string}>  $files
     * @param  DecisionPolicy|null  $onPause  how the runner answers pause cards; null = {@see DecisionPolicy::approveAll()}
     * @param  list<Grader>  $graders  graders scoped to THIS turn (they see the run up to and including it)
     */
    public function __construct(
        public string $prompt,
        public array $files = [],
        public ?DecisionPolicy $onPause = null,
        public array $graders = [],
    ) {}
}

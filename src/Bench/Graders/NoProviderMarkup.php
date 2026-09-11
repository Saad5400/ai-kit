<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Verdict;

/** Fails when provider control tokens (DeepSeek DSML, ChatML, <tool_call>, </think>…) leak into user-visible text. */
final class NoProviderMarkup extends AbstractGrader
{
    /** @var list<string> */
    private array $defaultMarkers = [
        '<｜DSML｜', '<|DSML|>', '<｜tool▁calls▁begin｜>', '<｜tool▁call▁begin｜>', '<｜tool▁sep｜>',
        '<｜begin▁of▁sentence｜>', '<｜end▁of▁sentence｜>', '<｜User｜>', '<｜Assistant｜>',
        '<tool_call>', '</tool_call>', '<|im_start|>', '<|im_end|>', '<think>', '</think>',
        '<|start_header_id|>', '<|eot_id|>', '[TOOL_CALLS]', '<function_calls>', '</function_calls>',
    ];

    /**
     * @param  list<string>|null  $markers  literal substrings; null reads `ai-kit.bench.provider_markup_patterns`
     */
    public function __construct(private ?array $markers = null) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $markers = $this->markers ?? $this->config('provider_markup_patterns', $this->defaultMarkers);
        $text = $run->allText();
        $hits = array_values(array_filter($markers, fn (string $marker): bool => str_contains($text, $marker)));

        return $hits === []
            ? $this->pass()
            : $this->fail('Provider markup leaked into the reply: '.implode(' ', $hits), ['hits' => $hits]);
    }
}

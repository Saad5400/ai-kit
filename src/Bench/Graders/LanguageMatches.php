<?php

namespace Saad\AiKit\Bench\Graders;

use Saad\AiKit\Bench\ScenarioRun;
use Saad\AiKit\Bench\Support\TextScrub;
use Saad\AiKit\Bench\Verdict;

/**
 * Script-ratio check against the scenario's expectedLanguage: an Arabic
 * scenario's final text must be ≥ threshold Arabic letters (code, URLs and
 * e-mails stripped first), and any Latin word glued to Arabic letters
 * ("Letني") fails outright. Skips when the scenario names no language.
 */
final class LanguageMatches extends AbstractGrader
{
    /**
     * @param  string|null  $language  override the scenario's expectedLanguage ('ar'|'en')
     */
    public function __construct(private ?string $language = null, private float $threshold = 0.5, private bool $lastOnly = true) {}

    public function grade(ScenarioRun $run): Verdict
    {
        $language = $this->language ?? $run->scenario->expectedLanguage;

        if ($language === null) {
            return $this->skip('Scenario names no expected language.');
        }

        $text = TextScrub::stripCodeAndUrls($this->lastOnly ? $run->lastText() : $run->allText());
        $arabic = TextScrub::arabicLetters($text);
        $latin = TextScrub::latinLetters($text);
        $total = $arabic + $latin;

        if ($total === 0) {
            $last = $run->lastTurn();

            return $last !== null && $last->isPaused()
                ? $this->pass('No prose to judge (paused on a card).')
                : $this->skip('No letters to judge.');
        }

        $glued = TextScrub::gluedScripts($text);

        if ($glued !== []) {
            return $this->fail('Mixed-script tokens: '.implode(', ', array_slice($glued, 0, 5)), ['glued' => $glued]);
        }

        $ratio = ($language === 'ar' ? $arabic : $latin) / $total;
        $evidence = ['arabic' => $arabic, 'latin' => $latin, 'ratio' => round($ratio, 3), 'expected' => $language];

        return $ratio >= $this->threshold
            ? $this->pass(sprintf('%s script %.0f%%', $language, $ratio * 100), $evidence)
            : $this->fail(sprintf('Expected %s but only %.0f%% of letters match (threshold %.0f%%).', $language, $ratio * 100, $this->threshold * 100), $evidence);
    }
}

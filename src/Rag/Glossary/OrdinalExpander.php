<?php

namespace Saad\AiKit\Rag\Glossary;

/**
 * Ordinals ↔ digits as a glossary: «الخامس» / «الخامسة» / «خامس» / `fifth` ↔
 * `5`, so «لكتشر الخامس» meets a lesson titled «محاضرة 5» and `chapter 3`
 * meets «الفصل الثالث». Compose it with the app's own sources in a
 * {@see GlossaryExpander}.
 */
final class OrdinalExpander implements GlossarySource
{
    /**
     * 1–10: Arabic masculine and feminine, with and without the article, and
     * English. Hamza/teh-marbuta spellings need no extra rows — matching folds.
     *
     * @var array<int, list<string>>
     */
    public const ORDINALS = [
        1 => ['الأول', 'الأولى', 'أول', 'أولى', 'first'],
        2 => ['الثاني', 'الثانية', 'ثاني', 'ثانية', 'second'],
        3 => ['الثالث', 'الثالثة', 'ثالث', 'ثالثة', 'third'],
        4 => ['الرابع', 'الرابعة', 'رابع', 'رابعة', 'fourth'],
        5 => ['الخامس', 'الخامسة', 'خامس', 'خامسة', 'fifth'],
        6 => ['السادس', 'السادسة', 'سادس', 'سادسة', 'sixth'],
        7 => ['السابع', 'السابعة', 'سابع', 'سابعة', 'seventh'],
        8 => ['الثامن', 'الثامنة', 'ثامن', 'ثامنة', 'eighth'],
        9 => ['التاسع', 'التاسعة', 'تاسع', 'تاسعة', 'ninth'],
        10 => ['العاشر', 'العاشرة', 'عاشر', 'عاشرة', 'tenth'],
    ];

    public function __construct(private readonly int $max = 10) {}

    public function terms(): iterable
    {
        foreach (self::ORDINALS as $number => $words) {
            if ($number > $this->max) {
                break;
            }

            yield new GlossaryTerm((string) $number, $words, 'ordinal');
        }
    }
}

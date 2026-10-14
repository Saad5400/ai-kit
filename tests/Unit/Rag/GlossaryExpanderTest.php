<?php

use Saad\AiKit\Rag\Glossary\ArrayGlossarySource;
use Saad\AiKit\Rag\Glossary\GlossaryExpander;
use Saad\AiKit\Rag\Glossary\GlossaryTerm;
use Saad\AiKit\Rag\Glossary\OrdinalExpander;

function platformGlossary(): ArrayGlossarySource
{
    return ArrayGlossarySource::fromArray([
        ['term' => 'محاضرة', 'alternatives' => ['لكتشر', 'lecture'], 'source' => 'platform'],
        ['term' => 'تجميعات', 'alternatives' => ['past exams', 'اختبارات سابقة']],
        ['term' => 'string', 'alternatives' => ['سترنق', 'النصوص']],
    ]);
}

it('expands a dialect term and an ordinal into OR-groups and a rewrite', function () {
    $expansion = (new GlossaryExpander([platformGlossary(), new OrdinalExpander]))->expand('لكتشر ٥');

    expect($expansion->groups[0])->toBe(['لكتشر', 'محاضره', 'lecture'])
        ->and($expansion->groups[1][0])->toBe('5')
        ->and($expansion->groups[1])->toContain('الخامس', 'fifth')
        // «5» is already the ordinal's preferred spelling, so it stays a digit.
        ->and($expansion->rewrites)->toBe(['محاضرة 5', 'lecture 5'])
        ->and($expansion->expansions[0])->toBe(['term' => 'لكتشر', 'alternatives' => ['محاضرة', 'lecture']])
        ->and($expansion->normalized)->toBe('لكتشر 5');
});

it('matches through a proclitic and keeps a preferred spelling as typed in rewrites', function () {
    $expansion = (new GlossaryExpander([platformGlossary(), new OrdinalExpander]))->expand('شرح المحاضرة الخامسة');

    // «المحاضرة» matched through its proclitic: the bare form joins the group.
    expect($expansion->groups[1])->toBe(['المحاضره', 'محاضره', 'لكتشر', 'lecture'])
        ->and($expansion->expansions[0]['alternatives'])->toBe(['لكتشر', 'lecture'])
        // «الخامسة» → its preferred «5», then the other-script `fifth`; the
        // same-script «الخامس»/«خامس» are left to the OR-group.
        ->and($expansion->rewrites)->toBe(['شرح المحاضرة 5', 'شرح المحاضرة fifth']);
});

it('matches the longest phrase first, across scripts', function () {
    $expansion = (new GlossaryExpander(platformGlossary()))->expand('Past Exams جافا');

    expect($expansion->groups)->toHaveCount(2)
        ->and($expansion->groups[0])->toBe(['past exams', 'تجميعات', 'اختبارات سابقه'])
        ->and($expansion->rewrites[0])->toBe('تجميعات جافا');
});

it('folds hamza, teh marbuta and marks on both sides of the match', function () {
    $source = new ArrayGlossarySource([new GlossaryTerm('مسؤولية', ['responsibility'])]);

    $expansion = (new GlossaryExpander($source))->expand('مسئوليّه');

    expect($expansion->expanded())->toBeTrue()
        ->and($expansion->groups[0])->toBe(['مسءوليه', 'responsibility']);
});

it('keeps every token and expands nothing when nothing matches', function () {
    $expansion = (new GlossaryExpander(platformGlossary()))->expand('حلقة for في جافا');

    expect($expansion->groups)->toBe([['حلقه'], ['for'], ['في'], ['جافا']])
        ->and($expansion->rewrites)->toBe([])
        ->and($expansion->expanded())->toBeFalse()
        ->and($expansion->matchTerms())->toBe(['حلقه', 'for', 'في', 'جافا']);
});

it('caps rewrites and alternatives', function () {
    $source = new ArrayGlossarySource([new GlossaryTerm('a', ['b', 'ج', 'د', 'e'])]);

    $expansion = (new GlossaryExpander($source, maxRewrites: 2, maxAlternatives: 2))->expand('b');

    expect($expansion->rewrites)->toBe(['a', 'ج'])
        ->and($expansion->groups[0])->toBe(['b', 'a', 'ج'])
        ->and($expansion->expansions[0]['alternatives'])->toBe(['a', 'ج']);
});

it('can match tokens only as written', function () {
    expect((new GlossaryExpander(platformGlossary(), proclitics: []))->expand('المحاضرة')->expanded())->toBeFalse();
});

it('handles empty and metacharacter-only queries', function () {
    expect((new GlossaryExpander(platformGlossary()))->expand('  ')->groups)->toBe([])
        ->and((new GlossaryExpander(platformGlossary()))->expand('() & |')->rewrites)->toBe([]);
});

it('serialises for diagnostics and the HTTP analysis block', function () {
    $array = (new GlossaryExpander(platformGlossary()))->expand('سترنق')->toArray();

    expect($array)->toHaveKeys(['original', 'normalized', 'groups', 'expansions', 'rewrites'])
        ->and($array['rewrites'][0])->toBe('string');
});

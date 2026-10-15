<?php

use Saad\AiKit\Transcription\AlignedSegment;
use Saad\AiKit\Transcription\TimedWord;
use Saad\AiKit\Transcription\TranscriptAligner;

/**
 * A port of the hybrid that produced catodemy's 2026-10 Java/Python
 * re-transcription: on that benchmark's 18 (text, clock) pairs it reproduced
 * the Python segments exactly in 17. The adversarial cases at the bottom are
 * catodemy QA's 2026-10-15 repros — each failed on the first port.
 */
function tw(int $start, int $end, string $text): TimedWord
{
    return new TimedWord($start, $end, $text);
}

/** @return list<TimedWord> */
function steadyWords(int $n, int $stepMs = 400, string $prefix = 'w'): array
{
    return array_map(fn (int $i): TimedWord => tw($i * $stepMs, $i * $stepMs + $stepMs - 50, $prefix.$i), range(0, $n - 1));
}

/** @param list<AlignedSegment> $segments */
function spans(array $segments): array
{
    return array_map(fn (AlignedSegment $s): array => [$s->startMs, $s->endMs], $segments);
}

it('normalizes the spellings two models give the same spoken word', function (string $a, string $b) {
    expect(TranscriptAligner::normalize($a))->toBe(TranscriptAligner::normalize($b));
})->with([
    'hamza forms' => ['أحمد', 'احمد'],
    'alef maqsura' => ['على', 'علي'],
    'taa marbuta' => ['جملة', 'جمله'],
    'harakat + tatweel' => ['مُـتَغَيِّر', 'متغير'],
    'definite article' => ['المتغير', 'متغير'],
    'punctuation + case' => ['String,', 'string'],
]);

it('anchors matched tokens to the clock and spreads the unmatched between them by length', function () {
    // "String" was heard as «سترينج» — no anchor; it takes the gap between its neighbours.
    $alignment = (new TranscriptAligner)->align(
        [tw(0, 400, 'نوعه'), tw(400, 1000, 'سترينج'), tw(1000, 1400, 'يعني')],
        'نوعه String يعني',
        null,
    );

    expect(spans($alignment->segments))->toBe([[0, 1400]])
        ->and($alignment->segments[0]->text)->toBe('نوعه String يعني')
        ->and($alignment->anchorRate)->toEqualWithDelta(2 / 3, 1e-9);
});

it('cuts after a sentence once it is 4 s long, after a comma at 12 s, and always at 20 s', function () {
    $words = [];
    $tokens = [];
    for ($i = 0; $i < 30; $i++) {
        $words[] = tw($i * 1000, $i * 1000 + 900, "ك{$i}");
        $tokens[] = "ك{$i}".match ($i) {
            2, 6 => '.',   // ends at 2.9 s (too short) and at 6.9 s (cut)
            13 => '،',     // a comma 6.9 s into the segment — too short
            default => '',
        };
    }

    expect(spans((new TranscriptAligner)->align($words, implode(' ', $tokens), null)->segments))
        ->toBe([[0, 6900], [7000, 27900], [28000, 29900]]);
});

it('spreads the text over the clip when there is no clock', function () {
    $alignment = (new TranscriptAligner)->align([], 'أ ب ت ث', 8_000);

    expect(spans($alignment->segments))->toBe([[0, 8_000]])
        ->and($alignment->anchorRate)->toBe(0.0);
});

it('returns nothing for empty text', function () {
    expect((new TranscriptAligner)->align([tw(0, 100, 'x')], "  \n ", 1000)->segments)->toBe([]);
});

// ── QA 2026-10-15 adversarial repros ────────────────────────────────────────

it('keeps text far longer than the clock (a repetition loop) in bounded segments', function () {
    // The clock heard 100 words over 40 s; the words model wrote those 100 then
    // looped one phrase 600 more times.
    $words = steadyWords(100);
    $text = implode(' ', array_map(fn (TimedWord $w) => $w->text, $words)).' '.str_repeat('نكرر الجملة هذي. ', 600);

    $segments = (new TranscriptAligner)->align($words, $text, 300_000)->segments;

    $maxTokens = max(array_map(fn (AlignedSegment $s) => count(preg_split('/\s+/u', $s->text)), $segments));
    $maxSpan = max(array_map(fn (AlignedSegment $s) => $s->endMs - $s->startMs, $segments));

    expect($maxTokens)->toBeLessThanOrEqual(80)
        ->and($maxSpan)->toBeLessThanOrEqual(20_500)
        ->and(end($segments)->endMs)->toBeLessThanOrEqual(300_000);
});

it('keeps segment starts monotonic and inside the clip when the clock overlaps or runs backwards', function () {
    $words = [tw(-80, 900, 'a'), tw(500, 5000, 'b'), tw(1000, 1200, 'c'), tw(1100, 1150, 'd'), tw(9000, 8000, 'e')];

    $segments = (new TranscriptAligner)->align($words, 'a. x y z b. c. q r d. e.', 10_000)->segments;
    $starts = array_map(fn (AlignedSegment $s) => $s->startMs, $segments);
    $sorted = $starts;
    sort($sorted);

    expect($starts)->toBe($sorted)
        ->and(min($starts))->toBeGreaterThanOrEqual(0);

    foreach ($segments as $s) {
        expect($s->endMs)->toBeGreaterThanOrEqual($s->startMs)->toBeLessThanOrEqual(10_000);
    }
});

it('never lets a clock time past the clip end leak out of the clip', function () {
    $segments = (new TranscriptAligner)->align([tw(1000, 2000, 'a'), tw(299_000, 305_000, 'b')], 'a. b.', 300_000)->segments;

    expect(max(array_map(fn (AlignedSegment $s) => $s->endMs, $segments)))->toBeLessThanOrEqual(300_000);
});

it('runs unanchored trailing text at speaking pace, not to the end of the clip', function () {
    // One unmatched English term after the last anchor at 1.4 s, in a 300 s clip.
    $segments = (new TranscriptAligner)->align([tw(0, 400, 'نوعه'), tw(1000, 1400, 'يعني')], 'نوعه يعني String', 300_000)->segments;

    expect($segments[count($segments) - 1]->endMs)->toBeLessThan(3_000);
});

it('aligns a worst-case 300 s window (repetitive tokens) in bounded time', function () {
    $words = array_map(fn (int $i) => tw($i * 330, $i * 330 + 300, $i % 3 === 0 ? 'يعني' : 'الـ'), range(0, 899));
    $text = implode(' ', array_map(fn (int $i) => $i % 2 ? 'يعني' : 'loop', range(0, 4000)));

    $t = microtime(true);
    $segments = (new TranscriptAligner)->align($words, $text, 300_000)->segments;

    expect((microtime(true) - $t) * 1000)->toBeLessThan(5000)
        ->and($segments)->not->toBeEmpty();
});

it('survives punctuation-only text', function () {
    expect((new TranscriptAligner)->align(steadyWords(10), '... — ؟ !! ,', 4000)->segments)->toHaveCount(1);
});

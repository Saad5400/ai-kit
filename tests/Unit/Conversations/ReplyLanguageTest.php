<?php

use Saad\AiKit\Conversations\ReplyLanguage;

it('detects the dominant script of a message', function (string $text, ?string $expected): void {
    expect(ReplyLanguage::detect($text))->toBe($expected);
})->with([
    'arabic' => ['أنشئ مقرر رياضيات بثلاث شُعب', 'ar'],
    'arabic with a latin name' => ['سوي اختبار في مقرر Java Programming من ١٠ اسئلة', 'ar'],
    'english' => ['make a math quiz in the current course with 5 basic question', 'en'],
    'english with an arabic name' => ['add 10 questions to رياضيات ١٠١', 'en'],
    'mixed' => ['create اختبار quiz رياضيات', null],
    'one letter' => ['a', null],
    'url only' => ['https://example.com/app/courses/8', null],
]);

it('phrases the hint for the detected language and stays silent otherwise', function (): void {
    expect(ReplyLanguage::hint('who are you?'))->toContain('reply in English')
        ->and(ReplyLanguage::hint('مين انت؟'))->toContain('reply in Arabic')
        ->and(ReplyLanguage::hint('a'))->toBeNull();
});

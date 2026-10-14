<?php

use Saad\AiKit\Rag\Text\KeyboardLayout;

it('reads US-layout keystrokes as the Arabic (101) layout', function () {
    expect(KeyboardLayout::toArabic("hda hgyg' td hgw,vi"))->toBe('ايش الغلط في الصوره')
        ->and(KeyboardLayout::toArabic('Hogh'))->toBe('أخلا')
        ->and(KeyboardLayout::toArabic('lphqvm 5'))->toBe('محاضرة 5');
});

it('reads Arabic-layout keystrokes as the US layout', function () {
    expect(KeyboardLayout::toLatin('اثممخ'))->toBe('hello')
        ->and(KeyboardLayout::toLatin('مهلاف'))->toBe('light')
        ->and(KeyboardLayout::toLatin('أ'))->toBe('H');
});

it('passes unmapped characters through', function () {
    expect(KeyboardLayout::toArabic('123 - ='))->toBe('123 - =')
        ->and(KeyboardLayout::toLatin('Java 1'))->toBe('Java 1');
});

it('round-trips single-key letters', function () {
    $keys = 'qwertyuiop[]asdfghjkl;\'zxcvnm,./`';

    expect(KeyboardLayout::toLatin(KeyboardLayout::toArabic($keys)))->toBe($keys);
});

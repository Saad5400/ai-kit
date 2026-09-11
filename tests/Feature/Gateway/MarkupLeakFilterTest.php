<?php

use Saad\AiKit\Gateway\DsmlToolCallParser;
use Saad\AiKit\Gateway\MarkupLeakFilter;

/** Push every delta, then flush — the emitted text and the filter. */
function filtered(array $deltas, ?array $patterns = null): array
{
    $filter = $patterns === null ? new MarkupLeakFilter : new MarkupLeakFilter($patterns);
    $out = [];

    foreach ($deltas as $delta) {
        $out[] = $filter->push($delta);
    }

    $out[] = $filter->flush();

    return [$out, $filter];
}

it('passes text without a marker through byte-for-byte, delta by delta', function () {
    [$out, $filter] = filtered(['Hel', 'lo ', 'wor', 'ld <b>bold</b> 3 < 4']);

    expect($out)->toBe(['Hel', 'lo ', 'wor', 'ld <b>bold</b> 3 < 4', ''])
        ->and($filter->leaked())->toBeFalse()
        ->and($filter->removed())->toBe('');
});

it('strips a leaked DSML block and everything after it', function () {
    [$out, $filter] = filtered([
        'لنبحث أولاً عن مقرراتك. ',
        '<｜DSML｜tool_calls>',
        '<｜DSML｜invoke name="ListRecords">',
        '</｜DSML｜invoke></｜DSML｜tool_calls>',
        'Here are your courses: none.',
    ]);

    expect(implode('', $out))->toBe('لنبحث أولاً عن مقرراتك. ')
        ->and($filter->leaked())->toBeTrue()
        ->and($filter->removed())->toStartWith('<｜DSML｜tool_calls>')
        ->and($filter->removed())->toEndWith('Here are your courses: none.');
});

it('holds a partial marker across delta boundaries and swallows it once it completes', function () {
    [$out, $filter] = filtered(['Searching', ' <', '｜DS', 'ML｜invoke name="x">']);

    expect($out)->toBe(['Searching', ' ', '', '', ''])
        ->and($filter->leaked())->toBeTrue()
        ->and($filter->removed())->toBe('<｜DSML｜invoke name="x">');
});

it('releases a held tail that turns out to be ordinary text', function () {
    [$out] = filtered(['see <', 'to', 'day>']);

    // `<t` could still become `<tool_call>`; `<to` cannot match anything.
    expect($out)->toBe(['see ', '', '<today>', ''])
        ->and(implode('', $out))->toBe('see <today>');
});

it('flushes a trailing `<` the stream ended on', function () {
    [$out] = filtered(['a < b <']);

    expect(implode('', $out))->toBe('a < b <');
});

it('catches the ASCII-degraded and generic markers too', function () {
    foreach (['<||DSML||invoke', '<|DSML|invoke', '<tool_call>{"name"', '<|tool_call|>', '<function_calls>', '<invoke name="a">', '<｜tool▁calls▁begin｜>'] as $marker) {
        [$out, $filter] = filtered(['ok ', $marker.' rest']);

        expect(implode('', $out))->toBe('ok ', $marker)
            ->and($filter->leaked())->toBeTrue($marker);
    }
});

it('can be disabled or reconfigured through config', function () {
    $off = MarkupLeakFilter::fromConfig(['enabled' => false]);

    expect($off->push('<｜DSML｜invoke>').$off->flush())->toBe('<｜DSML｜invoke>')
        ->and($off->leaked())->toBeFalse();

    [$out, $filter] = filtered(['plain <custom> tag'], ['<custom>']);

    expect(implode('', $out))->toBe('plain ')
        ->and($filter->leaked())->toBeTrue();
});

it('salvages intact DSML invoke blocks into tool calls', function () {
    $calls = DsmlToolCallParser::parse(<<<'DSML'
        <｜DSML｜tool_calls>
        <｜DSML｜invoke name="get_weather">
        <｜DSML｜parameter name="city" string="true">Riyadh</｜DSML｜parameter>
        <｜DSML｜parameter name="days">3</｜DSML｜parameter>
        <｜DSML｜parameter name="units">{"temp":"c"}</｜DSML｜parameter>
        </｜DSML｜invoke>
        <｜DSML｜invoke name="list_records">
        </｜DSML｜invoke>
        </｜DSML｜tool_calls>
        DSML);

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->name)->toBe('get_weather')
        ->and($calls[0]->arguments)->toBe(['city' => 'Riyadh', 'days' => 3, 'units' => ['temp' => 'c']])
        ->and($calls[0]->id)->toStartWith('salvaged_')
        ->and($calls[1]->name)->toBe('list_records')
        ->and($calls[1]->arguments)->toBe([]);
});

it('salvages the ASCII-degraded and orphan-invoke forms', function () {
    $degraded = DsmlToolCallParser::parse('<||DSML||invoke name="a"><||DSML||parameter name="q">"x"</||DSML||parameter></||DSML||invoke>');
    $orphan = DsmlToolCallParser::parse('some text <|DSML|invoke name="b"></|DSML|invoke>');

    expect($degraded[0]->name)->toBe('a')
        ->and($degraded[0]->arguments)->toBe(['q' => 'x'])
        ->and($orphan[0]->name)->toBe('b');
});

it('refuses a truncated invoke — a call the model never finished making', function () {
    expect(DsmlToolCallParser::parse('<｜DSML｜tool_calls><｜DSML｜invoke name="get_weather"><｜DSML｜parameter name="city">Ri'))->toBeNull()
        ->and(DsmlToolCallParser::parse('<tool_call>{"name":"x"}</tool_call>'))->toBeNull()
        ->and(DsmlToolCallParser::parse(''))->toBeNull();
});

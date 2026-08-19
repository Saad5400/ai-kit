<?php

use Saad\AiKit\Attachments\PdfTextLayer;

it('strips the bidi and zero-width controls pdftotext emits around Arabic runs', function () {
    $layer = new PdfTextLayer;

    expect($layer->stripControlJunk("\u{200F}مرحبا\u{200E} \u{FEFF}بالعالم\u{202B}"))->toBe('مرحبا بالعالم');
});

it('measures the junk ratio of a garbled layer', function () {
    $layer = new PdfTextLayer;

    expect($layer->junkRatio('clean text'))->toBe(0.0)
        ->and($layer->junkRatio("ab\u{FFFD}\u{FFFD}"))->toBe(0.5);
});

it('reads a missing poppler binary as no text layer, never an exception', function () {
    $layer = new PdfTextLayer(pdftotextBinary: '/nonexistent/pdftotext', pdfinfoBinary: '/nonexistent/pdfinfo');

    expect($layer->text('/tmp/whatever.pdf'))->toBeNull()
        ->and($layer->raw('/tmp/whatever.pdf'))->toBeNull()
        ->and($layer->pageCount('/tmp/whatever.pdf'))->toBeNull();
});

/**
 * Visual order, built rather than pasted: poppler emits the words of a line
 * back to front and each word's graphemes in display order, so the fixture
 * for it is that transformation applied to real Arabic prose. Constructing it
 * is the documentation — a pre-scrambled literal would prove nothing about
 * what the probe is actually looking at.
 */
function visualOrder(string $text): string
{
    $words = array_map(function (string $word): string {
        preg_match_all('/\X/u', $word, $graphemes);

        return implode('', array_reverse($graphemes[0]));
    }, preg_split('/\s+/u', trim($text)) ?: []);

    return implode(' ', array_reverse($words));
}

/** One paragraph of ordinary Arabic prose, function words and all. */
function arabicReport(): string
{
    return 'هذا التقرير يوضح النتائج التي حصلنا عليها في الفصل الأول من العام الدراسي، '
        .'وقد أشار المجلس إلى أن نسبة النجاح مرتفعة عن السنة الماضية.';
}

/** A PdfTextLayer whose poppler call is stubbed with the given output. */
function layerReturning(string $raw): PdfTextLayer
{
    return new class($raw) extends PdfTextLayer
    {
        public function __construct(private readonly string $output)
        {
            parent::__construct();
        }

        public function raw(string $absolutePath): ?string
        {
            return $this->output;
        }

        public function pageCount(string $absolutePath): ?int
        {
            return 1;
        }
    };
}

it('leaves a logical-order arabic text layer usable', function () {
    expect((new PdfTextLayer)->isReversedArabic(arabicReport()))->toBeFalse()
        ->and(layerReturning(arabicReport())->text('/tmp/whatever.pdf'))->toBe(arabicReport());
});

it('classifies a visual-order arabic text layer as no usable text layer', function () {
    // null is the existing "route me to vision" signal the scanned case uses.
    expect((new PdfTextLayer)->isReversedArabic(visualOrder(arabicReport())))->toBeTrue()
        ->and(layerReturning(visualOrder(arabicReport()))->text('/tmp/whatever.pdf'))->toBeNull();
});

it('leaves text carrying both orders alone', function () {
    // Both orders in one layer is not a reversal we can act on: rerouting
    // would pay for vision to re-read pages that extracted perfectly well.
    $mixed = arabicReport().' '.visualOrder(arabicReport());

    expect((new PdfTextLayer)->isReversedArabic($mixed))->toBeFalse();
});

it('never probes latin text for reversal', function () {
    $english = 'This report shows the results we obtained in the first term of the academic year, '
        .'and the council noted that the pass rate is higher than last year.';

    $layer = new PdfTextLayer;

    expect($layer->isReversedArabic($english))->toBeFalse()
        ->and($layer->isReversedArabic(visualOrder($english)))->toBeFalse()
        ->and($layer->arabicLetterRatio($english))->toBe(0.0);
});

it('does not flag arabic that carries no function words to compare', function () {
    // Long enough to probe, but `من` only ever appears inside longer words —
    // the boundary guard refuses those, so there is nothing to weigh.
    $arabic = 'تقرير سنوي شامل للنتائج والإحصاءات والملاحظات الختامية بصيغة مناسبة للطباعة';

    $layer = new PdfTextLayer;

    expect($layer->isReversedArabic($arabic))->toBeFalse()
        ->and($layer->isReversedArabic(visualOrder($arabic)))->toBeFalse();
});

it('does not flag a mostly numeric arabic table', function () {
    $table = "الرقم القيمة\n1 1234\n2 5678\n3 9012\n4 3456\n5 7890";

    $layer = new PdfTextLayer;

    expect($layer->isReversedArabic($table))->toBeFalse()
        ->and($layer->isReversedArabic(visualOrder($table)))->toBeFalse()
        // Digits stay out of the ratio, so the headings are read as Arabic.
        ->and($layer->arabicLetterRatio($table))->toBe(1.0);
});

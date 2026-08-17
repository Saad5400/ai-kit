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

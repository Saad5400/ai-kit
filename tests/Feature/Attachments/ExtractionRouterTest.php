<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Saad\AiKit\Attachments\ExtractionCache;
use Saad\AiKit\Attachments\ExtractionRouter;
use Saad\AiKit\Attachments\LocalTextExtractor;
use Saad\AiKit\Attachments\PdfTextLayer;

function router(?string $pdfText): ExtractionRouter
{
    $pdf = new class($pdfText) extends PdfTextLayer
    {
        public function __construct(private readonly ?string $result)
        {
            parent::__construct();
        }

        public function text(string $absolutePath, ?int $pageCount = null): ?string
        {
            return $this->result;
        }
    };

    return new ExtractionRouter($pdf, new LocalTextExtractor, new ExtractionCache(new Repository(new ArrayStore)));
}

it('routes images to vision without touching extractors', function () {
    $extraction = router(null)->route('/tmp/photo.jpg', 'image/jpeg');

    expect($extraction->isVision())->toBeTrue();
});

it('routes a born-digital pdf to free text and a scanned one to vision', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-pdf-');
    file_put_contents($path, '%PDF-fake');

    $born = router('extracted layer')->route($path, 'application/pdf');
    $scanned = router(null)->route($path, 'application/pdf');

    expect($born->isText())->toBeTrue()
        ->and($born->text)->toBe('extracted layer')
        ->and($scanned->isVision())->toBeTrue();

    @unlink($path);
});

it('routes known local formats to text and empty extractions to skip', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-md-');
    file_put_contents($path, 'notes');

    $text = router(null)->route($path, 'text/markdown', 'md');
    $unknown = router(null)->route($path, 'application/octet-stream', 'bin');

    expect($text->isText())->toBeTrue()
        ->and($text->text)->toBe('notes')
        ->and($unknown->isSkip())->toBeTrue();

    @unlink($path);
});

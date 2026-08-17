<?php

use Saad\AiKit\Attachments\LocalTextExtractor;

function ooxmlFixture(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-ooxml-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $xml) {
        $zip->addFromString($name, $xml);
    }

    $zip->close();

    return $path;
}

it('extracts docx body text', function () {
    $path = ooxmlFixture([
        'word/document.xml' => '<w:document><w:p><w:t>خطة المقرر</w:t></w:p><w:p><w:t>Course plan</w:t></w:p></w:document>',
    ]);

    expect((new LocalTextExtractor)->extract($path, 'docx'))->toBe('خطة المقرر Course plan');

    @unlink($path);
});

it('extracts pptx slides in natural order', function () {
    $path = ooxmlFixture([
        'ppt/slides/slide2.xml' => '<p:sld><a:p><a:t>Second</a:t></a:p></p:sld>',
        'ppt/slides/slide1.xml' => '<p:sld><a:p><a:t>First</a:t></a:p></p:sld>',
        'ppt/notes/notes1.xml' => '<p:notes><a:t>ignored</a:t></p:notes>',
    ]);

    expect((new LocalTextExtractor)->extract($path, 'pptx'))->toBe("First\n\nSecond");

    @unlink($path);
});

it('strips scripts, styles and tags from html', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-html-');
    file_put_contents($path, '<html><head><style>body{}</style><script>alert(1)</script></head><body><h1>Title</h1><p>Body &amp; more</p></body></html>');

    expect((new LocalTextExtractor)->extract($path, 'html'))->toBe('Title Body & more');

    @unlink($path);
});

it('reads plain-text extensions verbatim and skips unknown formats', function () {
    $path = tempnam(sys_get_temp_dir(), 'ai-kit-txt-');
    file_put_contents($path, "plain\ncontent");

    $extractor = new LocalTextExtractor;

    expect($extractor->extract($path, 'md'))->toBe("plain\ncontent")
        ->and($extractor->extract($path, 'exe'))->toBe('')
        ->and($extractor->extract('/nonexistent', 'docx'))->toBe('');

    @unlink($path);
});

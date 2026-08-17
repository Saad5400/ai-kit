<?php

namespace Saad\AiKit\Attachments;

use Throwable;
use ZipArchive;

/**
 * Free, on-box text extraction for the formats that need no model call:
 * OOXML documents and slides, HTML, and plain text. Routing is by
 * EXTENSION, not mime — a .docx/.pptx is detected as application/zip, so
 * the stored mime cannot distinguish them. Every failure path returns ''
 * rather than throwing: a malformed upload adds nothing, it never breaks
 * the turn. PDFs are not handled here — they carry the born-digital
 * decision and belong to {@see PdfTextLayer} via the {@see ExtractionRouter}.
 *
 * Safety is NOT bought by narrowing what this class reads — it comes from
 * the app's serving layer (what renders inline vs downloads as attachment).
 */
class LocalTextExtractor
{
    /** @var list<string> */
    public const PLAIN_TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'text', 'log', 'csv', 'tsv',
        'json', 'yaml', 'yml', 'xml', 'toml', 'ini', 'env', 'conf',
        'php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'svelte', 'py', 'rb', 'go', 'rs',
        'java', 'kt', 'c', 'h', 'cpp', 'hpp', 'cs', 'swift', 'sh', 'bash', 'sql',
        'css', 'scss', 'less', 'blade', 'twig', 'tex',
    ];

    public function extract(string $absolutePath, string $extension): string
    {
        $extension = strtolower($extension);

        try {
            return match (true) {
                $extension === 'docx' => $this->fromZipXml($absolutePath, ['word/document.xml']),
                $extension === 'pptx' => $this->fromZipXml($absolutePath, $this->slideEntries($absolutePath)),
                in_array($extension, ['html', 'htm', 'xhtml'], true) => $this->fromHtml($absolutePath),
                in_array($extension, self::PLAIN_TEXT_EXTENSIONS, true) => (string) @file_get_contents($absolutePath),
                default => '',
            };
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  list<string>  $entries
     */
    protected function fromZipXml(string $absolutePath, array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        try {
            $parts = [];

            foreach ($entries as $entry) {
                $xml = $zip->getFromName($entry);

                if ($xml === false) {
                    continue;
                }

                // Close each OOXML text run with a space so words from
                // adjacent runs do not concatenate.
                $text = trim(html_entity_decode(strip_tags(
                    (string) preg_replace('/<\/(w:p|a:p|w:t|a:t)>/', ' ', $xml),
                )));

                if ($text !== '') {
                    $parts[] = (string) preg_replace('/[ \t]+/', ' ', $text);
                }
            }

            return implode("\n\n", $parts);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    protected function slideEntries(string $absolutePath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        try {
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                    $entries[] = $name;
                }
            }

            sort($entries, SORT_NATURAL);

            return $entries;
        } finally {
            $zip->close();
        }
    }

    protected function fromHtml(string $absolutePath): string
    {
        $html = (string) @file_get_contents($absolutePath);

        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) preg_replace('/</', ' <', $html)));

        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }
}

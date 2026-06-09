<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\Concerns;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Builds a small, real .docx fixture carrying ${placeholder} tokens, on the fly
 * via PhpWord (no committed binary). Used by DocxTemplateService unit tests and
 * the docx upload / placeholders / generation feature tests.
 */
trait MakesDocxFixture
{
    /**
     * Create a .docx with the given paragraphs at a temp path and return that
     * path. Paragraphs are written verbatim, so embed ${tokens} directly, e.g.
     * 'Client: ${client_name}'.
     *
     * @param  array<int, string>  $paragraphs
     */
    protected function makeDocxFixture(array $paragraphs): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach ($paragraphs as $text) {
            $section->addText($text);
        }

        $path = tempnam(sys_get_temp_dir(), 'vizion_docx_fixture_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Raw bytes of a freshly-built .docx fixture (for storing on a faked disk).
     *
     * @param  array<int, string>  $paragraphs
     */
    protected function makeDocxFixtureBytes(array $paragraphs): string
    {
        $path = $this->makeDocxFixture($paragraphs);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Read the concatenated text of every paragraph in a .docx (best-effort,
     * straight from word/document.xml) — enough to assert that a placeholder was
     * substituted with its value.
     */
    protected function readDocxText(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        // Strip tags; the run text lives in <w:t>...</w:t> nodes.
        $text = strip_tags(str_replace(['<w:p>', '</w:p>'], ["\n", "\n"], $xml));

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1);
    }
}

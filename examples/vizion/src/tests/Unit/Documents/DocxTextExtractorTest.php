<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Services\Documents\DocxTextExtractor;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers DocxTextExtractor: the lightweight read-only docx text reader the AI
 * uses to understand the prose around ${placeholder} tokens (M7).
 *
 * We synthesise a minimal .docx (a ZIP with word/document.xml) in a temp file —
 * no PHPWord dependency on the read path, just ZipArchive + tag-stripping.
 */
class DocxTextExtractorTest extends TestCase
{
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    /**
     * Build a throwaway .docx whose document.xml carries $bodyXml. Each w:t run
     * is the readable text; we just embed the prose directly.
     */
    private function makeDocx(string $bodyText): string
    {
        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>' . htmlspecialchars($bodyText, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p></w:body>'
            . '</w:document>';

        $path = tempnam(sys_get_temp_dir(), 'vizion_docx_test_') . '.docx';
        $this->tmpFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        return $path;
    }

    public function test_extract_text_strips_xml_and_keeps_tokens(): void
    {
        $path = $this->makeDocx('Договор № ${agreement_number} на квартиру ${complex_name}.');
        $extractor = new DocxTextExtractor();

        $text = $extractor->extractText($path);

        $this->assertStringContainsString('${agreement_number}', $text);
        $this->assertStringContainsString('${complex_name}', $text);
        $this->assertStringContainsString('Договор', $text);
        // No XML tags survive.
        $this->assertStringNotContainsString('<w:', $text);
    }

    public function test_extract_text_throws_on_missing_file(): void
    {
        $extractor = new DocxTextExtractor();

        $this->expectException(RuntimeException::class);
        $extractor->extractText('/no/such/file.docx');
    }

    public function test_small_document_returns_full_preview_no_windows(): void
    {
        $path = $this->makeDocx('КП на ${complex_name}, цена ${estate_price_fmt}.');
        $extractor = new DocxTextExtractor();

        $result = $extractor->extractContextAroundPlaceholders($path, ['complex_name', 'estate_price_fmt']);

        // Below MAX_TOTAL_CHARS → whole text as preview, no per-token windows.
        $this->assertStringContainsString('${complex_name}', $result['preview']);
        $this->assertSame([], $result['contexts']);
    }

    public function test_large_document_returns_windowed_contexts(): void
    {
        // Pad well past MAX_TOTAL_CHARS so the windowing branch fires.
        $filler = str_repeat('лорем ипсум долор сит амет. ', 600);
        $body = $filler . ' Договор № ${agreement_number} от ${deal_date}. ' . $filler;

        $path = $this->makeDocx($body);
        $extractor = new DocxTextExtractor();

        $result = $extractor->extractContextAroundPlaceholders($path, ['agreement_number', 'deal_date']);

        $this->assertArrayHasKey('agreement_number', $result['contexts']);
        $this->assertArrayHasKey('deal_date', $result['contexts']);
        // Window around the token mentions the token + surrounding prose.
        $this->assertStringContainsString('${agreement_number}', $result['contexts']['agreement_number']);
        $this->assertStringContainsString('Договор', $result['contexts']['agreement_number']);
        // Each window is bounded (radius * 2 + token + ellipses).
        $this->assertLessThanOrEqual(
            DocxTextExtractor::MAX_TOTAL_CHARS,
            mb_strlen($result['contexts']['agreement_number']),
        );
    }

    public function test_absent_token_gets_empty_context(): void
    {
        $filler = str_repeat('текст наполнителя для превышения лимита. ', 600);
        $path = $this->makeDocx($filler . ' ${present_token} ' . $filler);
        $extractor = new DocxTextExtractor();

        $result = $extractor->extractContextAroundPlaceholders($path, ['present_token', 'missing_token']);

        $this->assertNotSame('', $result['contexts']['present_token']);
        $this->assertSame('', $result['contexts']['missing_token']);
    }
}

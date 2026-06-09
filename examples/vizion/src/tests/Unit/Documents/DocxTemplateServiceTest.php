<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Services\Documents\DocxTemplateService;
use RuntimeException;
use Tests\Feature\Documents\Concerns\MakesDocxFixture;
use Tests\TestCase;

/**
 * Unit tests for DocxTemplateService — placeholder extraction + substitution
 * against a real (PhpWord-built) .docx fixture, the field_mapping override
 * chain, missing-token collapse and cloneRow tabular fill.
 */
class DocxTemplateServiceTest extends TestCase
{
    use MakesDocxFixture;

    private DocxTemplateService $service;

    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocxTemplateService;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function track(string $path): string
    {
        $this->tmpFiles[] = $path;

        return $path;
    }

    /** @test */
    public function test_extract_placeholders_lists_declared_tokens(): void
    {
        $source = $this->track($this->makeDocxFixture([
            'Client: ${client_name}',
            'Price: ${estate_price} ₽',
        ]));

        $tokens = $this->service->extractPlaceholders($source);

        sort($tokens);
        $this->assertSame(['client_name', 'estate_price'], $tokens);
    }

    /** @test */
    public function test_extract_placeholders_throws_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->extractPlaceholders('/no/such/file.docx');
    }

    /** @test */
    public function test_fill_substitutes_direct_key_matches(): void
    {
        $source = $this->track($this->makeDocxFixture([
            'Client: ${client_name}',
            'Complex: ${complex_name}',
        ]));

        $target = $this->track($this->service->fill($source, [
            'client_name' => 'Ivan Petrov',
            'complex_name' => 'ЖК Север',
        ]));

        $text = $this->readDocxText($target);
        $this->assertStringContainsString('Ivan Petrov', $text);
        $this->assertStringContainsString('ЖК Север', $text);
        // No raw placeholder leaked.
        $this->assertStringNotContainsString('${client_name}', $text);
        $this->assertStringNotContainsString('${complex_name}', $text);
        // The filled .docx is still a valid template (no leftover variables).
        $this->assertSame([], $this->service->extractPlaceholders($target));
    }

    /** @test */
    public function test_fill_applies_field_mapping_override(): void
    {
        $source = $this->track($this->makeDocxFixture([
            'Name: ${name}',
            'Price: ${price}',
        ]));

        // The placeholders are name/price but the data keys are different —
        // field_mapping bridges placeholder -> dataKey.
        $target = $this->track($this->service->fill(
            $source,
            ['client_full_name' => 'Anna K.', 'estate_price' => '7 500 000'],
            ['name' => 'client_full_name', 'price' => 'estate_price'],
        ));

        $text = $this->readDocxText($target);
        $this->assertStringContainsString('Anna K.', $text);
        $this->assertStringContainsString('7 500 000', $text);
    }

    /** @test */
    public function test_fill_leaves_unmapped_placeholder_empty(): void
    {
        $source = $this->track($this->makeDocxFixture([
            'Filled: ${present}',
            'Missing: ${absent}',
        ]));

        // Only `present` is in $data; `absent` resolves to empty, not a failure.
        $target = $this->track($this->service->fill($source, ['present' => 'YES']));

        $text = $this->readDocxText($target);
        $this->assertStringContainsString('YES', $text);
        $this->assertStringNotContainsString('${absent}', $text);
        $this->assertStringNotContainsString('${present}', $text);
        $this->assertSame([], $this->service->extractPlaceholders($target));
    }

    /** @test */
    public function test_fill_throws_on_missing_source(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->fill('/no/such/template.docx', ['x' => 'y']);
    }

    /** @test */
    public function test_fill_coerces_non_string_values(): void
    {
        $source = $this->track($this->makeDocxFixture([
            'Count: ${count}',
            'Flag: ${flag}',
        ]));

        $target = $this->track($this->service->fill($source, [
            'count' => 42,
            'flag' => true,
        ]));

        $text = $this->readDocxText($target);
        $this->assertStringContainsString('42', $text);
        $this->assertStringContainsString('1', $text); // bool true -> "1"
    }

    /** @test */
    public function test_fill_applies_engine_filters_on_canonical_tokens(): void
    {
        // A docx token can carry a filter chain (estate.price|words). The engine
        // resolves the canonical key + filter and PHPWord sets the formatted text.
        $source = $this->track($this->makeDocxFixture([
            'Цена: ${estate.price|format} руб.',
            'Прописью: ${estate.price|words}',
            'Дата: ${deal.date|date}',
        ]));

        $target = $this->track($this->service->fill($source, [
            'estate.price' => '3500000',
            'deal.date' => '2024-06-15',
        ]));

        $text = $this->readDocxText($target);
        $this->assertStringContainsString('3 500 000', $text);
        $this->assertStringContainsString('три миллиона', $text);
        $this->assertStringContainsString('15.06.2024', $text);
        // No raw tokens left.
        $this->assertStringNotContainsString('${', $text);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Services\YamlTemplateParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class YamlTemplateParserTest extends TestCase
{
    use RefreshDatabase;

    private YamlTemplateParser $parser;

    private string $sampleProductYaml;

    private string $sampleCountryYaml;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new YamlTemplateParser;

        $this->sampleProductYaml = <<<'YAML'
name: "TestProduct"
has_multiple_copyright_holders: false
copyright_holder:
  name: "Test Holder"
  jurisdiction: "RU"
implementation_weeks: 12
training_hours_default: 15
YAML;

        $this->sampleCountryYaml = <<<'YAML'
name_full: "Республика Казахстан"
code: "kz"
currency_code: "KZT"
licensor:
  name: "Fallback Licensor"
  legal_form: "ТОО"
YAML;
    }

    public function test_parse_valid_product_yaml_returns_array(): void
    {
        $result = $this->parser->parse($this->sampleProductYaml);

        $this->assertIsArray($result);
        $this->assertEquals('TestProduct', $result['name']);
        $this->assertEquals(12, $result['implementation_weeks']);
    }

    public function test_parse_valid_country_yaml_returns_array(): void
    {
        $result = $this->parser->parse($this->sampleCountryYaml);

        $this->assertIsArray($result);
        $this->assertEquals('kz', $result['code']);
        $this->assertEquals('KZT', $result['currency_code']);
    }

    public function test_parse_invalid_yaml_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');

        // Tabs are forbidden in YAML — will cause a parse error.
        $this->parser->parse("key:\n\t- invalid");
    }

    public function test_build_context_merges_three_layers(): void
    {
        Template::create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'title' => 'MacroCRM',
            'content' => $this->sampleProductYaml,
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        Template::create([
            'code' => 'country_kz',
            'kind' => 'yaml',
            'title' => 'KZ',
            'content' => $this->sampleCountryYaml,
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        LicensorEntity::factory()->create([
            'country_code' => 'kz',
            'name' => 'DB Licensor',
        ]);

        $context = $this->parser->buildContext('macrocrm', 'kz');

        $this->assertArrayHasKey('product', $context);
        $this->assertArrayHasKey('country', $context);
        $this->assertArrayHasKey('licensor', $context);
        $this->assertArrayHasKey('custom', $context);

        $this->assertEquals('TestProduct', $context['product']['name']);
        $this->assertEquals('kz', $context['country']['code']);
        // DB entity takes precedence over YAML fallback.
        $this->assertEquals('DB Licensor', $context['licensor']['name']);
    }

    public function test_build_context_with_custom_override(): void
    {
        Template::create([
            'code' => 'product_macrosales',
            'kind' => 'yaml',
            'title' => 'MacroSales',
            'content' => "name: \"MacroSales\"\nimplementation_weeks: 12\n",
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        Template::create([
            'code' => 'country_uz',
            'kind' => 'yaml',
            'title' => 'UZ',
            'content' => "name_full: \"Uzbekistan\"\ncode: \"uz\"\ncurrency_code: \"UZS\"\n",
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        $customOverride = ['training_hours' => '20', 'start_date' => '01.07.2026'];

        $context = $this->parser->buildContext('macrosales', 'uz', $customOverride);

        $this->assertEquals($customOverride, $context['custom']);
        $this->assertEquals('MacroSales', $context['product']['name']);
    }

    public function test_build_context_licensor_fallback_to_yaml_when_no_db_entity(): void
    {
        Template::create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'title' => 'MacroCRM',
            'content' => "name: \"MacroCRM\"\nimplementation_weeks: 12\n",
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        Template::create([
            'code' => 'country_kz',
            'kind' => 'yaml',
            'title' => 'KZ',
            'content' => $this->sampleCountryYaml,
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        // No DB licensor entity for 'kz' — should fall back to YAML.
        $context = $this->parser->buildContext('macrocrm', 'kz');

        $this->assertNotNull($context['licensor']);
        $this->assertEquals('Fallback Licensor', $context['licensor']['name']);
    }

    public function test_build_context_licensor_override_by_id(): void
    {
        Template::create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'title' => 'MacroCRM',
            'content' => "name: \"MacroCRM\"\nimplementation_weeks: 12\n",
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        Template::create([
            'code' => 'country_kz',
            'kind' => 'yaml',
            'title' => 'KZ',
            'content' => $this->sampleCountryYaml,
            'version' => 1,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
        ]);

        $kzEntity = LicensorEntity::factory()->create(['country_code' => 'kz', 'name' => 'KZ Licensor']);
        $ruEntity = LicensorEntity::factory()->create(['country_code' => 'ru', 'name' => 'Override Licensor']);

        // Parser uses DB forCountry (no override param in buildContext — override is at Contract level).
        // We verify that the KZ entity is used, not the RU one.
        $context = $this->parser->buildContext('macrocrm', 'kz');

        $this->assertEquals('KZ Licensor', $context['licensor']['name']);
        $this->assertNotEquals($ruEntity->id, $context['licensor']['id']);
    }
}

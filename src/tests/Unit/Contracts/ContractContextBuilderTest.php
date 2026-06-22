<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\TemplateVariableType;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Services\ContractContextBuilder;
use App\Domain\Contracts\Services\YamlTemplateParser;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Services\CompanyRequisiteService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests for ContractContextBuilder::build().
 *
 * All tests use SQLite :memory: and fake YamlTemplateParser layer (via DB seeded Templates).
 */
class ContractContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ContractContextBuilder $builder;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ContractContextBuilder(new YamlTemplateParser, new CompanyRequisiteService);
        $this->author = User::factory()->create(['role' => Role::Manager]);

        // Seed minimal YAML templates needed by YamlTemplateParser.
        Template::factory()->create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'content' => "name: MacroCRM\nmodules:\n  - Contacts\n  - Deals\n",
        ]);

        Template::factory()->create([
            'code' => 'country_uz',
            'kind' => 'yaml',
            'content' => "name_full: \"Республика Узбекистан\"\ncurrency_code: UZS\n",
        ]);
    }

    public function test_builds_licensor_from_db_entity(): void
    {
        $licensor = LicensorEntity::factory()->forUz()->create([
            'name' => 'ООО Тест',
            'director_genitive' => 'Иванова И.И.',
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 100000,
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('ООО Тест', $ctx['licensor.name']);
        $this->assertSame('Иванова И.И.', $ctx['licensor.director_genitive']);
    }

    public function test_falls_back_to_yaml_licensor_if_no_db_entity(): void
    {
        // Add licensor block in country YAML
        Template::where('code', 'country_uz')->update([
            'content' => "name_full: \"Республика Узбекистан\"\ncurrency_code: UZS\nlicensor:\n  name: YAML Licensor\n  tax_id: \"12345\"\n",
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('YAML Licensor', $ctx['licensor.name']);
    }

    public function test_sublicensee_from_company(): void
    {
        $company = Company::factory()->create(['name' => 'Test Company Ltd']);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'source_company_id' => $company->id,
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('Test Company Ltd', $ctx['sublicensee.name']);
    }

    public function test_sublicensee_from_context_if_no_company(): void
    {
        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'source_company_id' => null,
            'total' => 0,
            'context' => [
                'sublicensee' => ['name' => 'Context Company'],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('Context Company', $ctx['sublicensee.name']);
    }

    public function test_custom_checkbox_typed_as_da_net(): void
    {
        TemplateVariable::factory()->create([
            'key' => 'has_training',
            'label' => 'Есть обучение',
            'var_type' => TemplateVariableType::Checkbox,
            'required' => false,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['has_training' => true],
            ],
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('Да', $ctx['custom.has_training']);
    }

    public function test_custom_checkbox_false_value(): void
    {
        TemplateVariable::factory()->create([
            'key' => 'is_nda',
            'label' => 'NDA',
            'var_type' => TemplateVariableType::Checkbox,
            'required' => false,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['is_nda' => false],
            ],
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('Нет', $ctx['custom.is_nda']);
    }

    public function test_custom_date_formatted(): void
    {
        TemplateVariable::factory()->create([
            'key' => 'start_date',
            'label' => 'Дата начала',
            'var_type' => TemplateVariableType::Date,
            'required' => false,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['start_date' => '2026-01-15'],
            ],
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('15.01.2026', $ctx['custom.start_date']);
    }

    public function test_required_variable_missing_throws_422(): void
    {
        TemplateVariable::factory()->create([
            'key' => 'contract_subject',
            'label' => 'Предмет договора',
            'var_type' => TemplateVariableType::Text,
            'required' => true,
            'default_value' => null,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            // 'contract_subject' not in custom
        ]);

        $this->expectException(ValidationException::class);

        $this->builder->build($doc);
    }

    public function test_termination_variable_not_required_for_normal_contract(): void
    {
        // Termination variables are seeded required with empty product/country
        // wildcards (so forContext returns them for every document). A NORMAL
        // contract must not be blocked by them — required-ness is kind-scoped.
        TemplateVariable::factory()->create([
            'key' => 'original_contract_number',
            'label' => 'Номер расторгаемого договора',
            'var_type' => TemplateVariableType::Text,
            'required' => true,
            'default_value' => null,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'kind' => 'contract',
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            // original_contract_number deliberately absent
        ]);

        // Must NOT throw — the termination var is irrelevant to a contract.
        $ctx = $this->builder->build($doc);

        $this->assertArrayHasKey('custom.original_contract_number', $ctx);
    }

    public function test_termination_variable_required_for_termination_agreement(): void
    {
        // The same wildcard-required variable IS enforced when the document is a
        // дополнительное соглашение о расторжении.
        TemplateVariable::factory()->create([
            'key' => 'original_contract_number',
            'label' => 'Номер расторгаемого договора',
            'var_type' => TemplateVariableType::Text,
            'required' => true,
            'default_value' => null,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'kind' => 'termination_agreement',
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            // original_contract_number missing → must throw on termination kind
        ]);

        $this->expectException(ValidationException::class);

        $this->builder->build($doc);
    }

    public function test_required_checkbox_never_throws(): void
    {
        TemplateVariable::factory()->create([
            'key' => 'mandatory_check',
            'label' => 'Обязательная галочка',
            'var_type' => TemplateVariableType::Checkbox,
            'required' => true,
            'default_value' => null,
            'is_active' => true,
            'product_codes' => [],
            'country_codes' => [],
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            // Checkbox not in custom — should not throw
        ]);

        // Should not throw
        $ctx = $this->builder->build($doc);

        $this->assertArrayHasKey('custom.mandatory_check', $ctx);
        $this->assertSame('Нет', $ctx['custom.mandatory_check']); // falsy default
    }

    public function test_total_in_words_in_context(): void
    {
        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 12300, // 123 UZS
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertArrayHasKey('total_in_words', $ctx);
        $this->assertStringContainsString('сто двадцать три', $ctx['total_in_words']);
    }

    public function test_contract_number_in_context_after_assignment(): void
    {
        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'city_code' => 'ТАШ',
            'number' => 'ТАШ-220/UZ',
            'currency' => 'UZS',
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('ТАШ-220/UZ', $ctx['contract.number']);
        $this->assertSame('ТАШ', $ctx['contract.city_code']);
    }

    // ---- N6: pinned requisite tests ----

    public function test_sublicensee_uses_pinned_requisite_over_company_columns(): void
    {
        $company = Company::factory()->create(['name' => 'Trade Name']);

        $requisite = CompanyRequisite::factory()->create([
            'company_id' => $company->id,
            'legal_name' => 'ТОО "Юридическое Имя"',
            'director_genitive' => 'Директора Иванова И.И.',
            'tax_id' => 'BIN123456',
            'address' => 'г. Алматы, ул. Тест 1',
            'bank_details' => ['bank' => 'Тест Банк', 'account' => 'KZ123'],
            'is_current' => true,
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'source_company_id' => $company->id,
            'company_requisite_id' => $requisite->id,
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        // Legal name from requisite, trade name from company
        $this->assertSame('Trade Name', $ctx['sublicensee.name']);
        $this->assertSame('ТОО "Юридическое Имя"', $ctx['sublicensee.legal_name']);
        $this->assertSame('Директора Иванова И.И.', $ctx['sublicensee.director_genitive']);
        $this->assertSame('BIN123456', $ctx['sublicensee.tax_id']);
        $this->assertSame('г. Алматы, ул. Тест 1', $ctx['sublicensee.address']);
    }

    public function test_sublicensee_falls_back_to_current_requisite_when_pin_absent(): void
    {
        $company = Company::factory()->create(['name' => 'Fallback Company']);

        CompanyRequisite::factory()->create([
            'company_id' => $company->id,
            'legal_name' => 'ООО "Фолбэк"',
            'is_current' => true,
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'source_company_id' => $company->id,
            'company_requisite_id' => null, // no pin
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        // Should use the current requisite
        $this->assertSame('ООО "Фолбэк"', $ctx['sublicensee.legal_name']);
    }

    public function test_sublicensee_falls_back_to_company_columns_when_no_requisites(): void
    {
        // Company with no requisites at all — legacy path
        $company = Company::factory()->create([
            'name' => 'Legacy Company',
            'tax_id' => 'LEG123',
            'address' => 'Legacy Address',
        ]);

        $doc = Document::factory()->create([
            'author_user_id' => $this->author->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'source_company_id' => $company->id,
            'company_requisite_id' => null,
            'total' => 0,
        ]);

        $ctx = $this->builder->build($doc);

        $this->assertSame('Legacy Company', $ctx['sublicensee.name']);
    }
}

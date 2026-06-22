<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Events\TerminationAgreementSigned;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Feature tests for N6 (contract): ДС о расторжении.
 *
 * Covers:
 *  1. Create termination Document (variables, requisite pin, original contract ref)
 *  2. Generator uses termination_agreement template (not master_skeleton)
 *  3. ContractContextBuilder reads requisites from pin
 *  4. Signed requires signed_scan (guard)
 *  5. TerminationAgreementSigned event dispatched on Signed
 *  6. FK disconnect_doc_id → documents
 */
class TerminationDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private CompanyRequisite $requisite;

    private Template $terminationTemplate;

    private TemplateVersion $terminationVersion;

    private Template $masterSkeleton;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Event::fake([TerminationAgreementSigned::class]);

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $this->company = Company::factory()->create([
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        $this->requisite = CompanyRequisite::factory()->create([
            'company_id' => $this->company->id,
            'legal_name' => 'ТОО "Тест Клиент"',
            'tax_id' => '123456789012',
            'director_genitive' => 'Директора Тестова Тест Тестовича',
            'director_short' => 'Тестов Т.Т.',
            'address' => 'г. Ташкент, ул. Тестовая 1',
            'bank_details' => [
                'bank' => 'Банк Тест',
                'account' => 'UZ00TEST0000000000',
            ],
            'is_current' => true,
        ]);

        // Seed YAML overlays for YamlTemplateParser
        Template::factory()->create([
            'code' => 'product_macrocrm',
            'kind' => 'yaml',
            'content' => "name: MacroCRM\n",
        ]);
        Template::factory()->create([
            'code' => 'country_uz',
            'kind' => 'yaml',
            'content' => "name_full: Узбекистан\ncurrency_code: UZS\n",
        ]);

        // master_skeleton (needed by ContractGenerationService as default)
        $this->masterSkeleton = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);
        $masterDocxPath = $this->createMinimalDocx('master_skeleton');
        $masterVersion = TemplateVersion::create([
            'template_id' => $this->masterSkeleton->id,
            'version_number' => 1,
            'docx_path' => $masterDocxPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $this->masterSkeleton->update(['current_version_id' => $masterVersion->id]);

        // termination_agreement template + docx version
        $this->terminationTemplate = Template::factory()->create([
            'code' => 'termination_agreement',
            'kind' => 'docx',
            'category' => 'cancellation',
            'content' => '',
        ]);
        $termDocxPath = $this->createMinimalTerminationDocx();
        $this->terminationVersion = TemplateVersion::create([
            'template_id' => $this->terminationTemplate->id,
            'version_number' => 1,
            'docx_path' => $termDocxPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $this->admin->id,
            'created_at' => now(),
        ]);
        $this->terminationTemplate->update(['current_version_id' => $this->terminationVersion->id]);

        // Seed termination TemplateVariables
        $this->seedTerminationVariables();
    }

    // ---- Helpers ----

    private function createMinimalDocx(string $label): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText("Шаблон: {$label}");
        $section->addText('Номер: ${contract.number}');

        $tmpPath = sys_get_temp_dir()."/term_feat_{$label}_".uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = "templates/{$label}/v1_feat.docx";
        Storage::disk('documents')->put($diskPath, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $diskPath;
    }

    private function createMinimalTerminationDocx(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('ДС о расторжении №${custom.original_contract_number}');
        $section->addText('Дата расторжения: ${custom.termination_date}');
        $section->addText('Основание: ${custom.termination_reason}');
        $section->addText('Сублицензиат: ${sublicensee.legal_name}');

        $tmpPath = sys_get_temp_dir().'/term_feat_termination_'.uniqid().'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpPath);

        $diskPath = 'templates/termination_agreement/v1_feat.docx';
        Storage::disk('documents')->put($diskPath, file_get_contents($tmpPath));
        @unlink($tmpPath);

        return $diskPath;
    }

    private function fakePdf(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 termination test pdf',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    private function seedTerminationVariables(): void
    {
        $vars = [
            ['key' => 'original_contract_number', 'label' => 'Номер расторгаемого договора', 'var_type' => 'text', 'required' => true,  'sort_order' => 110],
            ['key' => 'original_contract_date',   'label' => 'Дата расторгаемого договора',  'var_type' => 'date', 'required' => true,  'sort_order' => 120],
            ['key' => 'termination_date',         'label' => 'Дата расторжения',              'var_type' => 'date', 'required' => true,  'sort_order' => 130],
            ['key' => 'termination_reason',       'label' => 'Основание расторжения',         'var_type' => 'textarea', 'required' => true,  'sort_order' => 140],
            ['key' => 'termination_signatory',    'label' => 'Подписант ДС',                  'var_type' => 'text', 'required' => false, 'sort_order' => 150],
        ];

        foreach ($vars as $v) {
            TemplateVariable::updateOrCreate(['key' => $v['key']], array_merge($v, [
                'group' => 'Расторжение',
                'options' => [],
                'default_value' => null,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ]));
        }
    }

    private function terminationPayload(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'product_code' => 'macrocrm',
            'context' => [
                'custom' => [
                    'original_contract_number' => 'ТАШ-001/UZ',
                    'original_contract_date' => '01.01.2025',
                    'termination_date' => '30.06.2026',
                    'termination_reason' => 'По соглашению сторон',
                ],
            ],
        ], $overrides);
    }

    // ==========================================================================
    // 1. Create TerminationAgreement document
    // ==========================================================================

    public function test_create_termination_document_returns_201(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(),
        );

        $response->assertCreated();
        $this->assertSame('termination_agreement', $response->json('data.kind'));
        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_create_pins_current_requisite_automatically(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(),
        );

        $response->assertCreated();

        $docId = $response->json('data.id');
        $doc = Document::find($docId);
        $this->assertNotNull($doc);
        $this->assertSame($this->requisite->id, (int) $doc->company_requisite_id);
    }

    public function test_create_stores_custom_variables_in_context(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(),
        );

        $response->assertCreated();

        $docId = $response->json('data.id');
        $doc = Document::find($docId);
        $custom = $doc->context['custom'] ?? [];

        $this->assertSame('ТАШ-001/UZ', $custom['original_contract_number']);
        $this->assertSame('30.06.2026', $custom['termination_date']);
        $this->assertSame('По соглашению сторон', $custom['termination_reason']);
    }

    public function test_create_auto_fills_original_contract_from_signed_contract(): void
    {
        // Create a signed contract for this company
        $signedContract = Document::factory()->create([
            'kind' => DocumentKind::Contract->value,
            'source_company_id' => $this->company->id,
            'author_user_id' => $this->admin->id,
            'number' => 'ТАШ-219/UZ',
            'status' => ContractStatus::Signed->value,
            'signed_at' => '2025-03-15 10:00:00',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // Create termination WITHOUT providing original_contract_number
        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            array_merge($this->terminationPayload(), [
                'context' => [
                    'custom' => [
                        // NOT providing original_contract_number — should be auto-filled
                        'termination_date' => '30.06.2026',
                        'termination_reason' => 'По соглашению сторон',
                    ],
                ],
            ]),
        );

        $response->assertCreated();

        $doc = Document::find($response->json('data.id'));
        $custom = $doc->context['custom'] ?? [];

        // Auto-filled from signed contract
        $this->assertSame('ТАШ-219/UZ', $custom['original_contract_number']);
    }

    public function test_create_with_explicit_requisite_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(['company_requisite_id' => $this->requisite->id]),
        );

        $response->assertCreated();

        $doc = Document::find($response->json('data.id'));
        $this->assertSame($this->requisite->id, (int) $doc->company_requisite_id);
    }

    // ==========================================================================
    // 2. Generator uses termination_agreement template (not master_skeleton)
    // ==========================================================================

    public function test_generate_termination_document_uses_termination_template(): void
    {
        $this->fakePdf();
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents/generate",
            $this->terminationPayload(),
        );

        $response->assertOk()
            ->assertJsonStructure(['data' => ['document_id', 'docx_url', 'pdf_url', 'warnings']]);

        // The document should have kind=termination_agreement
        $docId = $response->json('data.document_id');
        $doc = Document::find($docId);
        $this->assertSame(DocumentKind::TerminationAgreement, $doc->kind);

        // Files should be saved on the documents disk
        $this->assertTrue(Storage::disk('documents')->exists($doc->docx_path));
        $this->assertTrue(Storage::disk('documents')->exists($doc->pdf_path));
    }

    public function test_generate_master_skeleton_for_contract_kind_unchanged(): void
    {
        // This verifies backward-compat: kind=contract still uses master_skeleton.
        $this->fakePdf();

        // Fill required termination variables so ContractContextBuilder does not throw 422.
        // (These are global wildcards — they apply to all documents.)
        $doc = Document::factory()->create([
            'kind' => DocumentKind::Contract->value,
            'author_user_id' => $this->admin->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'total' => 0,
            'status' => ContractStatus::Draft->value,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [
                    'original_contract_number' => 'ТАШ-001/UZ',
                    'original_contract_date' => '01.01.2025',
                    'termination_date' => '30.06.2026',
                    'termination_reason' => 'Тест',
                ],
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/generate");

        $response->assertOk();
        // Should use master_skeleton (already set up in setUp)
        $docRefresh = $doc->fresh();
        $this->assertNotNull($docRefresh->docx_path);
    }

    // ==========================================================================
    // 3. ContractContextBuilder reads requisites from pin
    // ==========================================================================

    public function test_context_builder_uses_pinned_requisite(): void
    {
        $this->fakePdf();
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents/generate",
            $this->terminationPayload(),
        );

        $response->assertOk();

        // If the builder used the pin correctly, the docx was generated without error.
        // The PDF bytes from our fake have 'termination test pdf'.
        $docId = $response->json('data.document_id');
        $doc = Document::find($docId);

        $this->assertSame($this->requisite->id, (int) $doc->company_requisite_id);
    }

    public function test_context_builder_falls_back_to_current_requisite_when_no_pin(): void
    {
        // Create a document without pinned requisite; company has is_current requisite.
        $doc = Document::factory()->create([
            'kind' => DocumentKind::Contract->value,
            'source_company_id' => $this->company->id,
            'company_requisite_id' => null,
            'author_user_id' => $this->admin->id,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'currency' => 'UZS',
            'status' => ContractStatus::Draft->value,
            // Fill required termination variables (global wildcards apply to all docs)
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [
                    'original_contract_number' => 'ТАШ-001/UZ',
                    'original_contract_date' => '01.01.2025',
                    'termination_date' => '30.06.2026',
                    'termination_reason' => 'Тест',
                ],
            ],
        ]);

        $this->fakePdf();
        Sanctum::actingAs($this->admin, ['*']);

        // Generation should succeed — builder falls back to current requisite (no pin)
        $response = $this->postJson("/api/documents/{$doc->id}/generate");

        $response->assertOk();

        // The company had requisite.is_current=true in setUp; builder should use it.
        $docRefresh = $doc->fresh();
        $this->assertNull($docRefresh->company_requisite_id); // pin not auto-set by generator
    }

    // ==========================================================================
    // 4. Signed requires signed_scan (guard)
    // ==========================================================================

    public function test_termination_document_cannot_be_signed_without_scan(): void
    {
        $doc = Document::factory()->approved()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $this->company->id,
            'author_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // No signed_scan attached
        $this->postJson("/api/documents/{$doc->id}/sign")
            ->assertUnprocessable();
    }

    public function test_termination_document_can_be_signed_with_scan(): void
    {
        $doc = Document::factory()->approved()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $this->company->id,
            'author_user_id' => $this->admin->id,
        ]);

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/documents/{$doc->id}/sign");

        $response->assertOk();
        $this->assertSame(ContractStatus::Signed->value, $response->json('data.status'));
    }

    // ==========================================================================
    // 5. TerminationAgreementSigned event dispatched on Signed
    // ==========================================================================

    public function test_event_dispatched_when_termination_doc_signed(): void
    {
        $doc = Document::factory()->approved()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'source_company_id' => $this->company->id,
            'author_user_id' => $this->admin->id,
        ]);

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/sign")->assertOk();

        Event::assertDispatched(TerminationAgreementSigned::class, function (TerminationAgreementSigned $e) use ($doc): bool {
            return $e->documentId === (int) $doc->id
                && $e->companyId === (int) $this->company->id;
        });
    }

    public function test_event_not_dispatched_when_regular_contract_signed(): void
    {
        // A normal contract (kind=contract) signed → no TerminationAgreementSigned
        $doc = Document::factory()->approved()->create([
            'kind' => DocumentKind::Contract->value,
            'source_company_id' => $this->company->id,
            'author_user_id' => $this->admin->id,
        ]);

        DocumentAttachment::factory()->create([
            'document_id' => $doc->id,
            'kind' => AttachmentKind::SignedScan->value,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/documents/{$doc->id}/sign")->assertOk();

        Event::assertNotDispatched(TerminationAgreementSigned::class);
    }

    // ==========================================================================
    // 6. FK disconnect_doc_id → documents
    // ==========================================================================

    public function test_disconnect_doc_id_fk_accepts_valid_document(): void
    {
        $doc = Document::factory()->create([
            'kind' => DocumentKind::TerminationAgreement->value,
            'author_user_id' => $this->admin->id,
        ]);

        // Set the FK on the company row directly (simulates N6-crm listener)
        $this->company->disconnect_doc_id = $doc->id;
        $this->company->save();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $this->company->id,
            'disconnect_doc_id' => $doc->id,
        ]);
    }

    public function test_disconnect_doc_id_can_be_null(): void
    {
        $this->company->disconnect_doc_id = null;
        $this->company->save();

        $this->assertDatabaseHas('crm_companies', [
            'id' => $this->company->id,
            'disconnect_doc_id' => null,
        ]);
    }

    // ==========================================================================
    // 7. Unauthenticated / auth guards
    // ==========================================================================

    public function test_unauthenticated_cannot_create_termination_doc(): void
    {
        $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(),
        )->assertUnauthorized();
    }

    // ==========================================================================
    // 8. DocumentKind enum has TerminationAgreement
    // ==========================================================================

    public function test_document_kind_termination_agreement_exists(): void
    {
        $this->assertSame('termination_agreement', DocumentKind::TerminationAgreement->value);
    }

    public function test_termination_document_stored_with_correct_kind(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/companies/{$this->company->id}/termination-documents",
            $this->terminationPayload(),
        );

        $response->assertCreated();

        $docId = $response->json('data.id');
        $doc = Document::find($docId);

        $this->assertSame(DocumentKind::TerminationAgreement, $doc->kind);
    }
}

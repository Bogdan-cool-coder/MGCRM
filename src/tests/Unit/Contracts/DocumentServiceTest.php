<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\ContractContextBuilder;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\ContractNumberingService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Contracts\Services\TemplateService;
use App\Domain\Contracts\Services\YamlTemplateParser;
use App\Domain\Crm\Services\CompanyRequisiteService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Services\Documents\GotenbergClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Covers DocumentService::hasActiveContractForDeal (S2.8) and
 * DocumentService::generateByTemplateCode (M7 cross-domain entry point).
 */
class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentService::class);
    }

    // ---- generateByTemplateCode helpers ----

    private function seedGenerationFixtures(): array
    {
        Storage::fake('documents');

        $author = User::factory()->create(['role' => Role::Manager]);

        // YAML overlays required by YamlTemplateParser.
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

        // Master skeleton template with a minimal docx version.
        $master = Template::factory()->create([
            'code' => 'master_skeleton',
            'kind' => 'docx',
            'content' => '',
        ]);

        $phpWord = new PhpWord;
        $phpWord->addSection()->addText('Номер: ${contract.number}');
        $tmpPath = sys_get_temp_dir().'/gen_by_code_'.uniqid().'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);

        $diskPath = 'templates/master_skeleton/v1_gen.docx';
        Storage::disk('documents')->put($diskPath, file_get_contents($tmpPath));
        @unlink($tmpPath);

        $version = TemplateVersion::create([
            'template_id' => $master->id,
            'version_number' => 1,
            'docx_path' => $diskPath,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_overridden' => false,
            'ai_remarks' => null,
            'pdf_ok' => true,
            'created_by_user_id' => $author->id,
            'created_at' => now(),
        ]);

        $master->update(['current_version_id' => $version->id]);

        return ['author' => $author, 'master' => $master];
    }

    private function makeGenerationService(): ContractGenerationService
    {
        return new ContractGenerationService(
            new TemplateService,
            new ContractContextBuilder(new YamlTemplateParser, new CompanyRequisiteService),
            new ContractNumberingService,
            app(DocumentService::class),
            new GotenbergClient,
        );
    }

    private function fakePdfResponse(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 stub',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function dealWithContract(ContractStatus $status, array $overrides = []): Deal
    {
        $deal = Deal::factory()->create();

        Document::factory()->create(array_merge([
            'source_deal_id' => $deal->id,
            'status' => $status->value,
        ], $overrides));

        return $deal;
    }

    public function test_has_active_contract_true_for_approved(): void
    {
        // Gate requires docx_path IS NOT NULL — simulates a real generated doc.
        $deal = $this->dealWithContract(ContractStatus::Approved, ['docx_path' => 'contracts/1/contract.docx']);

        $this->assertTrue($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_true_for_signed(): void
    {
        $deal = $this->dealWithContract(ContractStatus::Signed, ['docx_path' => 'contracts/1/contract.docx']);

        $this->assertTrue($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_true_for_uploaded(): void
    {
        $deal = $this->dealWithContract(ContractStatus::Uploaded, ['docx_path' => 'contracts/1/contract.docx']);

        $this->assertTrue($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_false_for_draft_submitted_review_rework(): void
    {
        foreach ([
            ContractStatus::Draft,
            ContractStatus::Submitted,
            ContractStatus::InReview,
            ContractStatus::NeedsRework,
        ] as $status) {
            $deal = $this->dealWithContract($status);

            $this->assertFalse(
                $this->service->hasActiveContractForDeal($deal->id),
                "Status {$status->value} must NOT count as a live contract.",
            );
        }
    }

    public function test_has_active_contract_false_when_approved_but_no_docx(): void
    {
        // Approved without docx_path = fake/seed doc, must NOT satisfy the gate.
        $deal = $this->dealWithContract(ContractStatus::Approved, ['docx_path' => null]);

        $this->assertFalse(
            $this->service->hasActiveContractForDeal($deal->id),
            'Approved doc without docx_path must not satisfy the won-gate.',
        );
    }

    public function test_has_active_contract_false_for_rejected_archived(): void
    {
        $rejectedDeal = $this->dealWithContract(ContractStatus::Rejected);
        $archivedDeal = $this->dealWithContract(ContractStatus::Archived);

        $this->assertFalse($this->service->hasActiveContractForDeal($rejectedDeal->id));
        $this->assertFalse($this->service->hasActiveContractForDeal($archivedDeal->id));
    }

    public function test_has_active_contract_false_when_no_document(): void
    {
        $deal = Deal::factory()->create();

        $this->assertFalse($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_scoped_by_deal_id(): void
    {
        // An approved contract belongs to one deal; a sibling deal has none.
        $this->dealWithContract(ContractStatus::Approved);
        $otherDeal = Deal::factory()->create();

        $this->assertFalse($this->service->hasActiveContractForDeal($otherDeal->id));
    }

    // ---- generateByTemplateCode tests ----

    public function test_generate_by_template_code_creates_document_and_files(): void
    {
        $fixtures = $this->seedGenerationFixtures();
        $this->fakePdfResponse();

        $deal = Deal::factory()->create([
            'currency' => 'UZS',
        ]);

        $doc = $this->service->generateByTemplateCode(
            deal: $deal,
            templateCode: 'master_skeleton',
            generationService: $this->makeGenerationService(),
            opts: ['product_code' => 'macrocrm', 'country_code' => 'uz', 'city' => 'Ташкент'],
            actorUserId: $fixtures['author']->id,
        );

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertNotNull($doc->docx_path);
        $this->assertNotNull($doc->pdf_path);
        $this->assertSame($deal->id, $doc->source_deal_id);
        $this->assertTrue(Storage::disk('documents')->exists($doc->docx_path));
        $this->assertTrue(Storage::disk('documents')->exists($doc->pdf_path));
    }

    public function test_generate_by_template_code_reuses_existing_draft_document(): void
    {
        $fixtures = $this->seedGenerationFixtures();
        $this->fakePdfResponse();

        $deal = Deal::factory()->create(['currency' => 'UZS']);

        // Pre-create a draft document linked to the deal.
        $existing = Document::factory()->create([
            'source_deal_id' => $deal->id,
            'status' => ContractStatus::Draft,
            'product_code' => 'macrocrm',
            'country_code' => 'uz',
            'city' => 'Ташкент',
        ]);

        $doc = $this->service->generateByTemplateCode(
            deal: $deal,
            templateCode: 'master_skeleton',
            generationService: $this->makeGenerationService(),
            actorUserId: $fixtures['author']->id,
        );

        // Same document row reused, not a new one.
        $this->assertSame($existing->id, $doc->id);
        $this->assertSame(1, Document::where('source_deal_id', $deal->id)->count());
    }

    public function test_generate_by_template_code_throws_when_template_not_found(): void
    {
        $deal = Deal::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->service->generateByTemplateCode(
            deal: $deal,
            templateCode: 'nonexistent_template_code',
            generationService: $this->makeGenerationService(),
            actorUserId: 1,
        );
    }
}

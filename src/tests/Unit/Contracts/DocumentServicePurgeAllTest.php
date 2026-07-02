<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ContractNumberSequence;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\AttachmentService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DocumentService::purgeAll() — the `docs` category cleaner for the selective
 * system-reset feature (docs/contracts/system-reset-api-contract.md §1 row 5,
 * §7 boundary decision).
 *
 * Covers the contract's non-negotiables for this category:
 *   - FK order (children before parents) so the delete succeeds on sqlite too.
 *   - Product decision P3: contract_number_sequences (numbering counters) SURVIVE.
 *   - §2 never-delete: templates / template_versions are NEVER touched.
 *   - Accurate per-table counters are returned (used by the audit meta, §4).
 *   - No authorization and no owned transaction — purgeAll() is a plain data
 *     operation, called by the (separately built) Support/System orchestrator.
 */
class DocumentServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DocumentService(
            app(AttachmentService::class),
            app(EntityLogService::class),
            app(DealService::class),
            app(CustomFieldService::class),
        );
    }

    public function test_purge_all_deletes_every_docs_category_table(): void
    {
        $document = Document::factory()->create();

        DocumentItem::factory()->count(2)->create(['document_id' => $document->id]);
        DocumentAttachment::factory()->count(2)->create(['document_id' => $document->id]);
        DocumentRevision::factory()->create(['document_id' => $document->id, 'version_number' => 1]);
        DocumentRemark::factory()->create(['document_id' => $document->id]);
        Approval::factory()->create(['document_id' => $document->id]);

        $this->assertSame(1, Document::query()->count());
        $this->assertSame(2, DocumentItem::query()->count());
        $this->assertSame(2, DocumentAttachment::query()->count());
        $this->assertSame(1, DocumentRevision::query()->count());
        $this->assertSame(1, DocumentRemark::query()->count());
        $this->assertSame(1, Approval::query()->count());

        $this->service->purgeAll();

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentItem::query()->count());
        $this->assertSame(0, DocumentAttachment::query()->count());
        $this->assertSame(0, DocumentRevision::query()->count());
        $this->assertSame(0, DocumentRemark::query()->count());
        $this->assertSame(0, Approval::query()->count());
    }

    public function test_purge_all_returns_accurate_per_table_counts(): void
    {
        $documentA = Document::factory()->create();
        $documentB = Document::factory()->create();

        DocumentItem::factory()->count(3)->create(['document_id' => $documentA->id]);
        DocumentItem::factory()->count(2)->create(['document_id' => $documentB->id]);
        DocumentAttachment::factory()->create(['document_id' => $documentA->id]);
        DocumentRevision::factory()->create(['document_id' => $documentA->id, 'version_number' => 1]);
        DocumentRevision::factory()->create(['document_id' => $documentA->id, 'version_number' => 2]);
        DocumentRemark::factory()->count(4)->create(['document_id' => $documentB->id]);
        Approval::factory()->count(2)->create(['document_id' => $documentA->id]);

        $counts = $this->service->purgeAll();

        $this->assertSame([
            'approvals' => 2,
            'document_remarks' => 4,
            'document_items' => 5,
            'document_attachments' => 1,
            'document_revisions' => 2,
            'documents' => 2,
        ], $counts);
    }

    public function test_purge_all_respects_fk_order_children_before_parents(): void
    {
        // A document with rows in every child table exercises the exact delete
        // order declared in purgeAll(): approvals -> remarks -> items ->
        // attachments -> revisions -> documents. If the order were wrong, this
        // would throw an FK-constraint violation on sqlite (FK enforcement ON
        // in the test suite) before ever reaching the assertions below.
        $document = Document::factory()->create();

        Approval::factory()->create(['document_id' => $document->id]);
        DocumentRemark::factory()->create(['document_id' => $document->id]);
        DocumentItem::factory()->create(['document_id' => $document->id]);
        DocumentAttachment::factory()->create(['document_id' => $document->id]);
        DocumentRevision::factory()->create(['document_id' => $document->id, 'version_number' => 1]);

        $this->service->purgeAll();

        $this->assertSame(0, Document::query()->count());
    }

    public function test_purge_all_with_no_data_returns_zero_counts(): void
    {
        $counts = $this->service->purgeAll();

        $this->assertSame([
            'approvals' => 0,
            'document_remarks' => 0,
            'document_items' => 0,
            'document_attachments' => 0,
            'document_revisions' => 0,
            'documents' => 0,
        ], $counts);
    }

    /**
     * Product decision P3: numbering counters carry legal/accounting continuity —
     * numbers must never repeat, so they are explicitly preserved even though
     * every document that consumed them is gone.
     */
    public function test_purge_all_preserves_contract_number_sequences(): void
    {
        Document::factory()->count(3)->create();
        $sequence = ContractNumberSequence::factory()->create([
            'city_code' => 'ТАШ',
            'country_code' => 'UZ',
            'current_number' => 245,
        ]);

        $this->service->purgeAll();

        $this->assertSame(0, Document::query()->count());
        $this->assertDatabaseHas('contract_number_sequences', [
            'id' => $sequence->id,
            'city_code' => 'ТАШ',
            'country_code' => 'UZ',
            'current_number' => 245,
        ]);
        $this->assertSame(1, ContractNumberSequence::query()->count());
    }

    /**
     * §2 hard never-delete: templates are document-generation CONFIG, not an
     * instance of the `docs` category. Documents referencing a template_version
     * are wiped, but the template + its version row survive untouched.
     */
    public function test_purge_all_never_touches_templates_or_template_versions(): void
    {
        $template = Template::factory()->create(['code' => 'master_skeleton']);
        $templateVersion = TemplateVersion::factory()->create(['template_id' => $template->id]);

        Document::factory()->create(['template_version' => $templateVersion->id]);

        $this->service->purgeAll();

        $this->assertSame(0, Document::query()->count());
        $this->assertDatabaseHas('templates', ['id' => $template->id, 'code' => 'master_skeleton']);
        $this->assertDatabaseHas('template_versions', ['id' => $templateVersion->id]);
        $this->assertSame(1, Template::query()->count());
        $this->assertSame(1, TemplateVersion::query()->count());
    }

    public function test_purge_all_only_removes_documents_category_rows_leaving_unrelated_tables_intact(): void
    {
        // A second, unrelated numbering sequence + template pair should be just
        // as untouched as the ones directly exercised above — purgeAll() must
        // not reach for any table outside its explicit allow-list.
        ContractNumberSequence::factory()->count(2)->create();
        Template::factory()->count(2)->create();

        Document::factory()->count(5)->create();

        $this->service->purgeAll();

        $this->assertSame(0, Document::query()->count());
        $this->assertSame(2, ContractNumberSequence::query()->count());
        $this->assertSame(2, Template::query()->count());
    }
}

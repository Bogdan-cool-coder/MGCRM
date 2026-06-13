<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers DocumentService::hasActiveContractForDeal (S2.8) — the cross-domain
 * entry point the Sales won-gate calls. "Live" = approved / signed / uploaded.
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

    private function dealWithContract(ContractStatus $status): Deal
    {
        $deal = Deal::factory()->create();

        Document::factory()->create([
            'source_deal_id' => $deal->id,
            'status' => $status->value,
        ]);

        return $deal;
    }

    public function test_has_active_contract_true_for_approved(): void
    {
        $deal = $this->dealWithContract(ContractStatus::Approved);

        $this->assertTrue($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_true_for_signed(): void
    {
        $deal = $this->dealWithContract(ContractStatus::Signed);

        $this->assertTrue($this->service->hasActiveContractForDeal($deal->id));
    }

    public function test_has_active_contract_true_for_uploaded(): void
    {
        $deal = $this->dealWithContract(ContractStatus::Uploaded);

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
}

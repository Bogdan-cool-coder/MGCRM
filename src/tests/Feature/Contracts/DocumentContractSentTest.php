<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto deal key action: a contract Document reaching `submitted` stamps the
 * source deal's contract_sent_at (DocumentService → DealService cross-domain).
 */
class DocumentContractSentTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentService::class);
    }

    public function test_submitting_a_contract_document_stamps_deal_contract_sent_at(): void
    {
        $user = User::factory()->create();
        $deal = Deal::factory()->create();

        $this->assertNull($deal->contract_sent_at);

        $doc = Document::factory()->draft()->create([
            'kind' => DocumentKind::Contract->value,
            'author_user_id' => $user->id,
            'source_deal_id' => $deal->id,
        ]);

        $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        $this->assertNotNull($deal->fresh()->contract_sent_at);

        $this->assertDatabaseHas('entity_logs', [
            'subject_type' => 'deal',
            'subject_id' => $deal->id,
            'action' => 'contract_sent',
        ]);
    }

    public function test_auto_stamp_is_idempotent_does_not_overwrite_existing(): void
    {
        $user = User::factory()->create();
        $existing = now()->subDays(5);
        $deal = Deal::factory()->create(['contract_sent_at' => $existing]);

        $doc = Document::factory()->draft()->create([
            'kind' => DocumentKind::Contract->value,
            'author_user_id' => $user->id,
            'source_deal_id' => $deal->id,
        ]);

        $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        // First-send date preserved (auto path never clobbers a manual date).
        $this->assertSame(
            $existing->toIso8601String(),
            $deal->fresh()->contract_sent_at->toIso8601String(),
        );
    }

    public function test_non_contract_document_does_not_stamp_contract_sent(): void
    {
        $user = User::factory()->create();
        $deal = Deal::factory()->create();

        $doc = Document::factory()->draft()->create([
            'kind' => DocumentKind::Invoice->value,
            'author_user_id' => $user->id,
            'source_deal_id' => $deal->id,
        ]);

        $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        $this->assertNull($deal->fresh()->contract_sent_at);
    }

    public function test_document_without_source_deal_is_a_no_op(): void
    {
        $user = User::factory()->create();

        $doc = Document::factory()->draft()->create([
            'kind' => DocumentKind::Contract->value,
            'author_user_id' => $user->id,
            'source_deal_id' => null,
        ]);

        // Must not throw — the contract transition never fails on a Sales concern.
        $updated = $this->service->transition($doc, ContractStatus::Submitted, $user->id);

        $this->assertSame(ContractStatus::Submitted, $updated->status);
    }
}

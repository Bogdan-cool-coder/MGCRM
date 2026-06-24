<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Contracts\Models\Document;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\PipelineSeeder;

/**
 * Shared helpers for Sales feature tests: seed the locked sales pipeline and
 * fetch it (with ordered stages) so deal flows have a real funnel to run on.
 */
trait SalesTestHelpers
{
    protected function seedSalesPipeline(): Pipeline
    {
        $this->seed(PipelineSeeder::class);

        return Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
    }

    protected function stageCode(Pipeline $pipeline, string $code): int
    {
        return (int) $pipeline->stages->firstWhere('code', $code)->id;
    }

    /**
     * Seed a genuinely-active contract for a deal so the S2.8 won-gate is
     * satisfied. DocumentService::hasActiveContractForDeal() requires both an
     * approved/signed/uploaded status AND a real docx_path (a fake-approved doc
     * with a NULL path no longer counts) — so the path is stamped here.
     */
    protected function activeContractFor(Deal $deal): Document
    {
        return Document::factory()->approved()->create([
            'source_deal_id' => $deal->id,
            'author_user_id' => $deal->owner_user_id,
            'docx_path' => "contracts/{$deal->id}/contract.docx",
        ]);
    }
}

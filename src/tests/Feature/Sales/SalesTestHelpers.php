<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

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
}
